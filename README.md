# FlowScribe OCR SDK (PHP)

PHP 8.2+ client for FlowScribe and Robo Connector OCR flows. Requires cURL, fileinfo and JSON.

```bash
composer require robo-meister/flow-scribe-api
```

## Synchronous OCR from Robo Connector

Configure a service token on the FlowScribe server and provide the same credential to the RC backend. Keep it out of browser code.

```php
use Robo\FlowScribeOcr\FlowScribeOcrClient;

$serviceToken = getenv('FLOWSCRIBE_SERVICE_TOKEN');
if ($serviceToken === false || $serviceToken === '') {
    throw new RuntimeException('Configure the FlowScribe service token in the RC backend.');
}
$client = new FlowScribeOcrClient(
    baseUrl: 'https://ocr.robo-meister.com',
    accessToken: $serviceToken,
    timeoutSeconds: 60
);

$scope = ['org_id' => 'org_123', 'workspace_id' => 'workspace_123'];
$result = $client->processDocument(__DIR__ . '/invoice.pdf', $scope + [
    'source_document_id' => 'rc_document_123',
    'user_id' => 'user_123',
    'context_type' => 'Document',
    'context_id' => 'rc_document_123',
    'document_name' => 'Vendor invoice.pdf',
    'document_type' => 'auto',
    'mode' => 'fuse',
    'journal_csv' => true,
    'correlation_id' => 'request_123',
]);

$ocrDocumentId = $result['document_id'] ?? null;
$fields = $result['parsed']['fields'] ?? [];

```

RC is responsible for authorizing its user and supplying the correct document/organisation context. A service token is an identity mechanism, not a subscription override. The SDK sends the configured token as Bearer and never retries anonymously after an error.

The planned server setting `FLOWSCRIBE_ACCESS_MODE=free` belongs to the FlowScribe service. The SDK does not read it or suppress billing/authentication errors. Server support must be deployed separately.

### Endpoint and credential compatibility

| Operation | Endpoint | Credential in the inspected server |
| --- | --- | --- |
| `processDocument()` / `processDocumentForReview()` | `POST /process` | Configured service token for backend integration; server also has session/public modes |
| `ingestFlowScribe()` / `ingestUniversalDropzoneDocument()` | `POST /api/integration/flowscribe/ingest` | Token accepted by the existing linked-account flow |
| `ingestRcInvoice()` / `ingestRcDocument()` | `POST /api/rc/invoice-ocr` | Token accepted by the existing linked-account flow |
| `flowScribeStatus()` / `rcInvoiceStatus()` | Matching status endpoint | Same linked-account identity and scope |
| `health()` / `metadata()` | `GET /health` / `GET /metadata` | Subject to deployment auth/workspace configuration |

A configured service token for `/process` is not automatically accepted by ingest. The SDK sends a credential; the server validates it. A client created without a token uses public OCR only if the deployment permits it.

These boundaries were inspected at [FlowScribe commit ec54b6c](https://github.com/Robo-Meister/ocr/tree/ec54b6c57dd4c30dc1244a3043085a42285675bc). They are not a live deployment certification.

## Request options

`processDocument()`, `ingestFlowScribe()` and `ingestRcInvoice()` share upload options:

| Options | Behaviour |
| --- | --- |
| `document_type` | Default/`auto` allows server classification; `auto` is omitted unless `send_auto_document_type=true` |
| `mode` | `fuse` or `split` |
| `journal_csv` | Boolean request for CSV output |
| `config_path` / `config` | JSON file / array uploaded as a JSON file part; file path takes precedence |
| `source_document_id`, `workspace_id`, `org_id`, `user_id` | Caller document and identity metadata |
| `context_type`, `context_id`, `document_name` | Context and document metadata |
| `return_review_payload`, `include_storage`, `include_preview` | Boolean requests; availability depends on the server |
| `correlation_id`, `idempotency_key` | Forwarded headers; sending a key does not guarantee server deduplication |
| `rc_callback_url` | RC base URL; the inspected server appends `/api/integration/document/ocr-completed` |

Pass actual booleans for boolean options. Identity fields are multipart fields; the SDK also mirrors workspace, organisation, source document, correlation, idempotency and callback values to the corresponding `X-*` / `Idempotency-Key` headers.

`rc_callback_url` is a base URL such as `https://connector.example.com`. Callback delivery, signing and recipient compatibility require separate server work. Synchronous callers can consume the response directly.

### Custom dictionaries

```php
$result = $client->processDocument(__DIR__ . '/invoice.pdf', [
    'config' => [
        'fields' => [
            'invoiceNumber' => ['keywords' => ['Invoice #']],
        ],
    ],
]);
// Or: ['config_path' => __DIR__ . '/dictionary.json']

```

Configuration is uploaded as a file, not a regular JSON form value. Generated files are cleaned up on success, HTTP failure and transport failure. JSON encoding fails before creating a temporary file. Caller-owned `config_path` files are retained.

## Review results and compatibility

`processDocumentForReview()`, `ingestUniversalDropzoneDocument()` and `ingestRcDocument()` request `auto` classification, `mode=fuse` and review/storage/preview fields by default. Explicit boolean overrides are preserved.

Every method returns the decoded server JSON object unchanged. The SDK does not construct review data, infer missing confidence or mark a queued job as completed.

```php
$result = $client->processDocumentForReview(__DIR__ . '/invoice.pdf', $scope);
$reviewPayload = $result['review_payload'] ?? null;
if ($reviewPayload === null) {
    // Use the existing RC adapter for the legacy response, or show review as unavailable.
    $legacyFields = $result['parsed']['fields'] ?? [];
}

```

The inspected `/process` implementation does not return the documented review envelope yet. `review_payload`, preview and storage must be treated as optional until server support is verified. Unknown response fields and server schema versions are preserved for forward compatibility.

## Queued jobs and status

Use a client with a token accepted by the linked-account ingest flow. Reuse the organisation/workspace context when reading status.

```php
$linkedToken = getenv('FLOWSCRIBE_ACCESS_TOKEN');
if ($linkedToken === false || $linkedToken === '') {
    throw new RuntimeException('Configure a token accepted by the linked-account flow.');
}
$integrationClient = new FlowScribeOcrClient(accessToken: $linkedToken);
$queued = $integrationClient->ingestFlowScribe(__DIR__ . '/invoice.pdf', $scope + [
    'source_document_id' => 'rc_document_123',
    'correlation_id' => 'request_123',
]);
$jobId = $queued['data']['job']['id'];
$status = $integrationClient->flowScribeStatus($jobId, $scope);
$job = $status['data']['job'];
if ($job['status'] === 'completed') {
    $result = $job['result'];
}
// Handle queued/processing, failed and blocked separately.

```

`ingestRcDocument()` remains a compatibility alias with review defaults for the RC invoice bridge. Prefer `ingestFlowScribe()` for the general FlowScribe ingest route.

Status methods make one HTTP request. Polling cadence belongs to the application. There are no automatic upload retries: a timeout can happen after the server accepted a document, and the inspected ingest route does not deduplicate requests based on `Idempotency-Key`. Durable jobs and reliable callbacks remain server dependencies.

## Diagnostics

```php
$health = $client->health($scope);
$metadata = $client->metadata($scope);
$diagnostics = $client->diagnostics('org_123', 'workspace_123');

```

`diagnostics()` tries the integration diagnostics endpoint and falls back to health/metadata only on 404, 405 or 501. The fallback keeps the token and supplied organisation/workspace headers. Authentication and other server errors propagate.

The optional options arrays on health, metadata and both status methods are backward compatible with their existing calls.

## Errors

```php
use Robo\FlowScribeOcr\FlowScribeOcrException;

try {
    $result = $client->processDocument(__DIR__ . '/invoice.pdf', $scope);
} catch (FlowScribeOcrException $exception) {
    $httpStatus = $exception->getStatusCode();
    $errorCode = $exception->getErrorCode();
    $correlationId = $exception->getCorrelationId();
    $rawBody = $exception->getRawResponseBody();
    $decodedBody = $exception->getResponseBody();
    $curlError = $exception->getTransportErrorCode();
}

```

HTTP errors preserve the status and response bodies. Correlation comes from `X-Correlation-Id`, then `X-Trace-Id`, then the error body's `correlation_id`. Invalid JSON or a non-object JSON response also raises an exception, including on HTTP 2xx; response headers and raw bytes remain available. An empty JSON object is accepted.

Transport errors preserve the cURL error number and have no fabricated HTTP status. Request options are not billing decisions, and 401/402/403/429 errors are never rewritten as success. JSON encoding errors in a supplied config array continue to raise `JsonException`.

## Tests

```bash
php tests/test.php
# or
composer test
```

The runner starts a temporary loopback HTTP fixture, chooses a free port, runs the regression suite and cleans up the server and files. It requires PHP CLI with cURL/fileinfo/JSON and process functions on Linux/macOS. No real credentials, live OCR or Composer install are required.

GitHub Actions runs PHP 8.2 and 8.3 syntax checks, Composer metadata validation and the same suite. Tests cover upload bytes/config cleanup, context on status/diagnostics, legacy and prospective review responses, job states, HTTP/JSON failures and timeouts. They verify the SDK transport contract; they do not measure OCR accuracy or certify the live server.

See [the three SDK tasks and remaining server dependencies](docs/sdk-stabilization.md). Changes remain unreleased until a release is deliberately tagged; RC should update its Composer lockfile only after selecting the intended SDK release.
