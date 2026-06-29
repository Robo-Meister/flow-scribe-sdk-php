<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

function request_headers_lower(): array {
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower($name)] = $value;
    }
    return $headers;
}

if ($path === '/process' || $path === '/api/integration/flowscribe/ingest' || $path === '/api/rc/invoice-ocr') {
    echo json_encode([
        'path' => $path,
        'headers' => request_headers_lower(),
        'post' => $_POST,
        'files' => array_keys($_FILES),
    ]);
    return;
}

if ($path === '/api/integration/flowscribe/diagnostics') {
    http_response_code(404);
    echo json_encode(['code' => 'not_found', 'message' => 'missing']);
    return;
}

if ($path === '/health') {
    echo json_encode(['ok' => true]);
    return;
}

if ($path === '/metadata') {
    echo json_encode(['dictionaries' => ['invoice', 'cv']]);
    return;
}

if ($path === '/error') {
    http_response_code(422);
    header('X-Correlation-Id: response-correlation-123');
    echo json_encode(['code' => 'invalid_document', 'message' => 'Invalid document']);
    return;
}

if ($path === '/gateway-error') {
    http_response_code(502);
    header('Content-Type: text/html');
    header('X-Correlation-Id: gateway-correlation-456');
    echo '<html><body>Bad Gateway</body></html>';
    return;
}

http_response_code(404);
echo json_encode(['code' => 'not_found']);
