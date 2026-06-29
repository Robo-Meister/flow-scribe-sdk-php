<?php
require __DIR__ . '/../src/FlowScribeOcrClient.php';
require __DIR__ . '/../src/FlowScribeOcrException.php';

use Robo\FlowScribeOcr\FlowScribeOcrClient;
use Robo\FlowScribeOcr\FlowScribeOcrException;

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$baseUrl = $argv[1] ?? 'http://127.0.0.1:18080';
$client = new FlowScribeOcrClient($baseUrl, 'token', 5, 'test-agent');
$file = tempnam(sys_get_temp_dir(), 'flowscribe-ocr-test-doc-');
file_put_contents($file, 'sample document');

try {
    $result = $client->processDocument($file, [
        'document_type' => 'invoice',
        'mode' => 'fuse',
        'journal_csv' => true,
        'source_document_id' => 'src-123',
        'workspace_id' => 'ws-123',
        'org_id' => 'org-123',
        'user_id' => 'user-123',
        'context_type' => 'UniversalDropzone',
        'context_id' => 'ctx-123',
        'rc_callback_url' => 'https://example.test/callback',
        'document_name' => 'invoice.pdf',
        'return_review_payload' => true,
        'include_storage' => true,
        'include_preview' => false,
        'idempotency_key' => 'idem-123',
        'correlation_id' => 'corr-123',
    ]);

    foreach ([
        'x-workspace-id' => 'ws-123',
        'x-org-id' => 'org-123',
        'x-source-document-id' => 'src-123',
        'x-correlation-id' => 'corr-123',
        'idempotency-key' => 'idem-123',
        'x-rc-callback-url' => 'https://example.test/callback',
    ] as $name => $value) {
        assert_true(($result['headers'][$name] ?? null) === $value, "Missing header $name");
    }

    foreach ([
        'document_type' => 'invoice',
        'mode' => 'fuse',
        'journal_csv' => 'true',
        'source_document_id' => 'src-123',
        'workspace_id' => 'ws-123',
        'org_id' => 'org-123',
        'user_id' => 'user-123',
        'context_type' => 'UniversalDropzone',
        'context_id' => 'ctx-123',
        'rc_callback_url' => 'https://example.test/callback',
        'document_name' => 'invoice.pdf',
        'return_review_payload' => 'true',
        'include_storage' => 'true',
        'include_preview' => 'false',
    ] as $name => $value) {
        assert_true(($result['post'][$name] ?? null) === $value, "Missing field $name");
    }

    $auto = $client->processDocumentForReview($file);
    assert_true(!array_key_exists('document_type', $auto['post']), 'auto document_type should be omitted by default');
    assert_true(($auto['post']['mode'] ?? null) === 'fuse', 'review helper should set mode');
    assert_true(($auto['post']['return_review_payload'] ?? null) === 'true', 'review helper should request review payload');
    assert_true(($auto['post']['include_storage'] ?? null) === 'true', 'review helper should include storage');
    assert_true(($auto['post']['include_preview'] ?? null) === 'true', 'review helper should include preview');

    $forcedAuto = $client->processDocument($file, ['document_type' => 'auto', 'send_auto_document_type' => true]);
    assert_true(($forcedAuto['post']['document_type'] ?? null) === 'auto', 'send_auto_document_type should force auto field');

    $diag = $client->diagnostics('org-123', 'ws-123');
    assert_true(($diag['diagnostics_available'] ?? true) === false, 'diagnostics should fallback');
    assert_true(($diag['health']['ok'] ?? false) === true, 'diagnostics fallback should call health');
    assert_true(($diag['metadata']['dictionaries'][0] ?? null) === 'invoice', 'diagnostics fallback should call metadata');

    $before = glob(sys_get_temp_dir() . '/flowscribe-ocr-config-*') ?: [];
    $client->processDocument($file, ['config' => ['fields' => ['foo' => ['keywords' => ['Foo']]]]]);
    $after = glob(sys_get_temp_dir() . '/flowscribe-ocr-config-*') ?: [];
    assert_true(count($before) === count($after), 'temporary config file cleanup failed');

    $reflection = new ReflectionClass($client);
    $request = $reflection->getMethod('request');
    $request->setAccessible(true);
    try {
        $request->invoke($client, 'GET', '/error');
        throw new RuntimeException('Expected exception was not thrown');
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (FlowScribeOcrException $exception) {
        assert_true($exception->getStatusCode() === 422, 'status code not preserved');
        assert_true($exception->getCorrelationId() === 'response-correlation-123', 'correlation id not preserved');
        assert_true($exception->getErrorCode() === 'invalid_document', 'error code not preserved');
        assert_true(($exception->getResponseBody()['code'] ?? null) === 'invalid_document', 'response body not preserved');
        assert_true(str_contains($exception->getRawResponseBody() ?? '', 'invalid_document'), 'raw response body not preserved');
    }

    try {
        $request->invoke($client, 'GET', '/gateway-error');
        throw new RuntimeException('Expected non-JSON exception was not thrown');
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (FlowScribeOcrException $exception) {
        assert_true($exception->getStatusCode() === 502, 'non-JSON status code not preserved');
        assert_true($exception->getCorrelationId() === 'gateway-correlation-456', 'non-JSON correlation id not preserved');
        assert_true($exception->getResponseBody() === [], 'non-JSON response body should be empty');
        assert_true(str_contains($exception->getRawResponseBody() ?? '', 'Bad Gateway'), 'non-JSON raw response body not preserved');
    }
} finally {
    @unlink($file);
}

echo "All PHP SDK tests passed\n";
