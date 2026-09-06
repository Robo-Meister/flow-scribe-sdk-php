<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$log = getenv('FLOWSCRIBE_SDK_TEST_LOG');
if ($log) {
    file_put_contents($log, json_encode(['path' => $path, 'authorization' => $headers['authorization'] ?? null]) . "\n", FILE_APPEND | LOCK_EX);
}
if (in_array($path, ['/process', '/api/integration/flowscribe/ingest', '/api/rc/invoice-ocr'], true)) {
    $scenario = $_POST['document_name'] ?? '';
    if (preg_match('/^http-(401|402|403|422|429|500|502)$/', $scenario, $matches)) {
        http_response_code((int) $matches[1]);
        echo json_encode(['code' => 'fixture_error', 'message' => 'Fixture error', 'correlation_id' => 'body-correlation']);
        return;
    }
    if ($scenario === 'legacy-result') {
        echo json_encode(['exit_code' => 0, 'document_id' => 'ocr-1', 'parsed' => ['fields' => ['invoiceNumber' => 'INV-1']]]);
        return;
    }
    if ($scenario === 'review-result') {
        // Prospective response, not a claim that the pinned server returns it.
        echo json_encode(['exit_code' => 0, 'review_payload' => [
            'schemaVersion' => 'flowscribe.ocr-draft-review.v1', 'sourceDocumentId' => $_POST['source_document_id'] ?? null,
            'ocrDocumentId' => 'ocr-1', 'extractedFields' => ['invoiceNumber' => 'INV-1'], 'extensionFromServer' => ['keep' => true],
        ]]);
        return;
    }
    if ($scenario === 'queued-result') {
        http_response_code(201);
        echo json_encode(['status' => 'ok', 'data' => ['job' => ['id' => 'queued', 'status' => 'queued', 'document_id' => 'ocr-1']]]);
        return;
    }
    if ($scenario === 'slow-result') {
        usleep(1500000);
        echo json_encode(['exit_code' => 0]);
        return;
    }
    $uploads = [];
    foreach ($_FILES as $name => $file) {
        $uploads[$name] = ['name' => $file['name'], 'type' => $file['type'], 'error' => $file['error'], 'content' => file_get_contents($file['tmp_name'])];
    }
    echo json_encode(['path' => $path, 'headers' => $headers, 'post' => $_POST, 'files' => array_keys($_FILES), 'uploads' => $uploads]);
    return;
}
foreach (['/api/integration/flowscribe/status/', '/api/rc/invoice-ocr/status/'] as $prefix) {
    if (str_starts_with($path, $prefix)) {
        $id = rawurldecode(substr($path, strlen($prefix)));
        $state = in_array($id, ['queued', 'processing', 'completed', 'failed', 'blocked'], true) ? $id : 'completed';
        echo json_encode([
            'status' => 'ok',
            'data' => ['job' => [
                'id' => $id, 'status' => $state, 'document_id' => 'ocr-1',
                'error' => $state === 'failed' ? 'OCR failed' : null,
                'result' => $state === 'completed' ? ['exit_code' => 0, 'document_id' => 'ocr-1'] : null,
            ]],
            'headers' => $headers, 'request_uri' => $_SERVER['REQUEST_URI'],
        ]);
        return;
    }
}
if ($path === '/api/integration/flowscribe/diagnostics') {
    $code = ($headers['x-org-id'] ?? '') === 'denied' ? 403 : 404;
    http_response_code($code);
    echo json_encode(['code' => $code === 403 ? 'forbidden' : 'not_found', 'message' => 'missing']);
    return;
}
if ($path === '/health') {
    echo json_encode(['ok' => true, 'headers' => $headers]);
    return;
}
if ($path === '/metadata') {
    echo json_encode(['dictionaries' => ['invoice', 'cv'], 'headers' => $headers]);
    return;
}
if ($path === '/error') {
    http_response_code(422);
    header('X-Correlation-Id: response-correlation-123');
    echo json_encode(['code' => 'invalid_document', 'message' => 'Invalid document', 'correlation_id' => 'lower-priority-body']);
    return;
}
if ($path === '/gateway-error') {
    http_response_code(502);
    header('Content-Type: text/html');
    header('X-Correlation-Id: gateway-correlation-456');
    echo '<html><body>Bad Gateway</body></html>';
    return;
}
if ($path === '/trace-error') {
    http_response_code(500);
    header('X-Trace-Id: trace-correlation');
    echo json_encode(['code' => 'server_error', 'correlation_id' => 'lower-priority-body']);
    return;
}
$rawResponses = [
    '/invalid-json' => '{broken', '/list-json' => '[{"exit_code":0}]', '/empty-list-json' => '[]',
    '/scalar-json' => '42', '/null-json' => 'null', '/empty-body' => '', '/empty-object-json' => '{}',
];
if (array_key_exists($path, $rawResponses)) {
    header('X-Correlation-Id: invalid-response-correlation');
    echo $rawResponses[$path];
    return;
}
http_response_code(404);
echo json_encode(['code' => 'not_found']);
