<?php
// Included by run.php; all HTTP requests target the local fixture server.
function sdk_expect_exception(callable $action, string $class = \Robo\FlowScribeOcr\FlowScribeOcrException::class): Throwable {
    try {
        $action();
    } catch (Throwable $exception) {
        assert_true($exception instanceof $class, 'Unexpected exception: ' . $exception::class);
        return $exception;
    }
    throw new RuntimeException('Expected exception was not thrown');
}
$scope = ['org_id' => 'org-123', 'workspace_id' => 'ws-123', 'correlation_id' => 'corr-123'];
assert_true($result['uploads']['file']['content'] === 'sample document', 'Document bytes must reach the server');
assert_true($result['headers']['authorization'] === 'Bearer token', 'Bearer must be forwarded');

$config = ['fields' => ['invoiceNumber' => ['keywords' => ['Invoice #']]]];
$before = glob(sys_get_temp_dir() . '/flowscribe-ocr-config-*') ?: [];
$configResult = $client->processDocument($file, ['config' => $config]);
assert_true($configResult['uploads']['config']['type'] === 'application/json', 'Config must be a JSON file part');
assert_true($configResult['uploads']['config']['error'] === UPLOAD_ERR_OK, 'Config upload failed');
assert_true(json_decode($configResult['uploads']['config']['content'], true) === $config, 'Config bytes changed');
assert_true(!isset($configResult['post']['config']), 'Config must not be a regular form field');
$configPath = tempnam(sys_get_temp_dir(), 'flowscribe-test-config-');
try {
    file_put_contents($configPath, json_encode($config));
    $pathResult = $client->processDocument($file, ['config_path' => $configPath, 'config' => ['ignored' => true]]);
    assert_true(json_decode($pathResult['uploads']['config']['content'], true) === $config, 'config_path precedence changed');
    assert_true(is_file($configPath), 'Caller-owned config must not be deleted');
} finally {
    unlink($configPath);
}
sdk_expect_exception(fn () => $client->processDocument($file, ['config' => ['bad' => "\xB1"]]), JsonException::class);
sdk_expect_exception(fn () => $client->processDocument($file, ['config' => $config, 'document_name' => 'http-422']));
$after = glob(sys_get_temp_dir() . '/flowscribe-ocr-config-*') ?: [];
sort($before);
sort($after);
assert_true($after === $before, 'Temporary config leaked on success, JSON encoding, or HTTP failure');

$anonymous = new \Robo\FlowScribeOcr\FlowScribeOcrClient($baseUrl);
$anonymousResult = $anonymous->processDocument($file);
assert_true(!isset($anonymousResult['headers']['authorization']), 'Anonymous requests must omit Authorization');
foreach ([
    'processDocumentForReview' => '/process', 'ingestUniversalDropzoneDocument' => '/api/integration/flowscribe/ingest',
    'ingestRcDocument' => '/api/rc/invoice-ocr', 'ingestRcInvoice' => '/api/rc/invoice-ocr',
] as $method => $endpoint) {
    $response = $client->$method($file, $scope + ['return_review_payload' => false, 'include_storage' => false, 'include_preview' => false]);
    assert_true($response['path'] === $endpoint, $method . ' endpoint changed');
    assert_true($response['headers']['x-workspace-id'] === 'ws-123', $method . ' lost workspace');
    foreach (['include_preview', 'include_storage', 'return_review_payload'] as $option) {
        assert_true($response['post'][$option] === 'false', $method . ' override ignored: ' . $option);
    }
}
foreach (['flowScribeStatus', 'rcInvoiceStatus'] as $method) {
    foreach (['queued', 'processing', 'completed', 'failed', 'blocked'] as $state) {
        $response = $client->$method($state, $scope);
        assert_true($response['data']['job']['status'] === $state, 'Job state must be preserved, including failed/blocked');
        foreach (['x-workspace-id' => 'ws-123', 'x-org-id' => 'org-123', 'x-correlation-id' => 'corr-123', 'authorization' => 'Bearer token'] as $header => $value) {
            assert_true($response['headers'][$header] === $value, 'Status lost ' . $header);
        }
        assert_true($response['data']['job']['result'] === ($state === 'completed' ? ['exit_code' => 0, 'document_id' => 'ocr-1'] : null), 'Job result changed');
    }
    $jobId = 'job/with ?query#fragment%';
    $response = $client->$method($jobId, $scope);
    assert_true(str_ends_with($response['request_uri'], rawurlencode($jobId)), 'Job ID must stay in one path segment');
    assert_true($response['data']['job']['id'] === $jobId, 'Job ID changed');
    assert_true($client->$method('completed')['data']['job']['status'] === 'completed', 'Existing one-argument status call must work');
}
foreach (['health', 'metadata'] as $method) {
    $response = $client->$method($scope);
    assert_true($response['headers']['x-workspace-id'] === 'ws-123', $method . ' lost workspace');
}
foreach (['health', 'metadata'] as $key) {
    assert_true($diag[$key]['headers']['x-workspace-id'] === 'ws-123', 'Diagnostics fallback lost workspace');
    assert_true($diag[$key]['headers']['x-org-id'] === 'org-123', 'Diagnostics fallback lost organisation');
    assert_true($diag[$key]['headers']['authorization'] === 'Bearer token', 'Diagnostics fallback lost auth');
}
$logPath = getenv('FLOWSCRIBE_SDK_TEST_LOG');
$logBefore = $logPath ? count(file($logPath)) : null;
$denied = sdk_expect_exception(fn () => $client->diagnostics('denied', 'ws-123'));
assert_true($denied->getStatusCode() === 403, 'Diagnostics must not mask authorization errors');
if ($logPath) {
    assert_true(count(file($logPath)) === $logBefore + 1, '403 diagnostics must not trigger fallback requests');
}
$legacy = $client->processDocumentForReview($file, ['document_name' => 'legacy-result']);
assert_true($legacy['parsed']['fields']['invoiceNumber'] === 'INV-1', 'Legacy fields changed');
assert_true(!array_key_exists('review_payload', $legacy), 'SDK must not invent a review payload');
$review = $client->processDocumentForReview($file, ['document_name' => 'review-result', 'source_document_id' => 'rc-1']);
assert_true($review['review_payload']['sourceDocumentId'] === 'rc-1', 'Review source ID changed');
assert_true($review['review_payload']['ocrDocumentId'] === 'ocr-1', 'OCR ID changed');
assert_true($review['review_payload']['extensionFromServer']['keep'] === true, 'Unknown server fields must be preserved');
$queued = $client->ingestFlowScribe($file, ['document_name' => 'queued-result']);
assert_true($queued['data']['job']['status'] === 'queued', 'Queued result must not become completed');
foreach ([401, 402, 403, 422, 429, 500, 502] as $httpCode) {
    $logBefore = $logPath ? count(file($logPath)) : null;
    $failure = sdk_expect_exception(fn () => $client->processDocument($file, ['document_name' => 'http-' . $httpCode]));
    assert_true($failure->getStatusCode() === $httpCode, 'HTTP code changed');
    assert_true($failure->getCorrelationId() === 'body-correlation', 'Server correlation_id fallback lost');
    assert_true($failure->getErrorCode() === 'fixture_error', 'Machine error code changed');
    if ($logPath) {
        assert_true(count(file($logPath)) === $logBefore + 1, 'SDK must not retry or fall back anonymously');
    }
}
$traceFailure = sdk_expect_exception(fn () => $request->invoke($client, 'GET', '/trace-error'));
$previousAccessMode = getenv('FLOWSCRIBE_ACCESS_MODE');
try {
    putenv('FLOWSCRIBE_ACCESS_MODE=free');
    $billingFailure = sdk_expect_exception(fn () => $client->processDocument($file, ['document_name' => 'http-402']));
    assert_true($billingFailure->getStatusCode() === 402, 'A server ENV setting must not become a client-side billing bypass');
} finally {
    putenv($previousAccessMode === false ? 'FLOWSCRIBE_ACCESS_MODE' : 'FLOWSCRIBE_ACCESS_MODE=' . $previousAccessMode);
}
assert_true($traceFailure->getCorrelationId() === 'trace-correlation', 'X-Trace-Id fallback missing');
foreach (['/invalid-json', '/list-json', '/empty-list-json', '/scalar-json', '/null-json', '/empty-body'] as $endpoint) {
    $failure = sdk_expect_exception(fn () => $request->invoke($client, 'GET', $endpoint));
    assert_true($failure->getStatusCode() === 200, 'Malformed response lost HTTP status');
    assert_true($failure->getCorrelationId() === 'invalid-response-correlation', 'Malformed response lost correlation');
    assert_true($failure->getRawResponseBody() !== null, 'Malformed response lost raw body');
}
assert_true($request->invoke($client, 'GET', '/empty-object-json') === [], 'Empty JSON object is valid');

$shortTimeoutClient = new \Robo\FlowScribeOcr\FlowScribeOcrClient($baseUrl, 'token', 1);
$before = glob(sys_get_temp_dir() . '/flowscribe-ocr-config-*') ?: [];
$timeout = sdk_expect_exception(fn () => $shortTimeoutClient->processDocument($file, ['document_name' => 'slow-result', 'config' => $config]));
assert_true($timeout->getStatusCode() === null, 'Transport failure must not invent an HTTP status');
assert_true($timeout->getTransportErrorCode() === CURLE_OPERATION_TIMEDOUT, 'Timeout must preserve cURL error number');
$after = glob(sys_get_temp_dir() . '/flowscribe-ocr-config-*') ?: [];
sort($before);
sort($after);
assert_true($after === $before, 'Temporary config leaked after transport failure');
