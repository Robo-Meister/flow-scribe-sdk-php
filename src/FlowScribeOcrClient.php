<?php

namespace Robo\FlowScribeOcr;

use CURLFile;
use JsonException;

final class FlowScribeOcrClient
{
    public function __construct(
        private readonly string $baseUrl = 'https://ocr.robo-meister.com',
        private readonly ?string $accessToken = null,
        private readonly int $timeoutSeconds = 60,
        private readonly string $userAgent = 'robo-flowscribe-ocr-php-sdk/1.0'
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->request('GET', '/metadata');
    }

    /**
     * Process a document synchronously through the public OCR API.
     *
     * Supported options:
     * - document_type: string dictionary name from metadata(); "auto" is omitted from multipart by default
     * - mode: "fuse" or "split"
     * - journal_csv: bool
     * - config_path: string path to a JSON dictionary override file
     * - config: array<string, mixed> dictionary override; sent as a generated JSON file
     * - source_document_id, workspace_id, org_id, user_id, context_type, context_id
     * - rc_callback_url, document_name, return_review_payload, include_storage, include_preview
     * - idempotency_key, correlation_id
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function processDocument(string $filePath, array $options = []): array
    {
        return $this->upload('/process', $filePath, $options);
    }

    /**
     * Queue a document through the authenticated FlowScribe integration API.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function ingestFlowScribe(string $filePath, array $options = []): array
    {
        return $this->upload('/api/integration/flowscribe/ingest', $filePath, $options);
    }

    /**
     * Queue an invoice through the authenticated RC invoice OCR bridge.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function ingestRcInvoice(string $filePath, array $options = []): array
    {
        return $this->upload('/api/rc/invoice-ocr', $filePath, $options);
    }

    /**
     * Process a document with defaults suited for Robo review payload rendering.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function processDocumentForReview(string $filePath, array $options = []): array
    {
        return $this->processDocument($filePath, $this->reviewDefaults($options));
    }

    /**
     * Queue a Universal Dropzone document through the FlowScribe integration API.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function ingestUniversalDropzoneDocument(string $filePath, array $options = []): array
    {
        return $this->ingestFlowScribe($filePath, $this->reviewDefaults($options));
    }

    /**
     * Queue a Robo Connector document through the RC OCR bridge.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function ingestRcDocument(string $filePath, array $options = []): array
    {
        return $this->ingestRcInvoice($filePath, $this->reviewDefaults($options));
    }

    /**
     * Return FlowScribe/Robo Connector diagnostics. Falls back to /health and /metadata
     * when the integration diagnostics endpoint is unavailable.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(?string $organisationId = null, ?string $workspaceId = null): array
    {
        $headers = [];
        if ($organisationId !== null && $organisationId !== '') {
            $headers[] = 'X-Org-Id: ' . $organisationId;
            $headers[] = 'X-Organisation-Id: ' . $organisationId;
        }
        if ($workspaceId !== null && $workspaceId !== '') {
            $headers[] = 'X-Workspace-Id: ' . $workspaceId;
        }

        try {
            return $this->request('GET', '/api/integration/flowscribe/diagnostics', headers: $headers);
        } catch (FlowScribeOcrException $exception) {
            if (!in_array($exception->getStatusCode(), [404, 405, 501], true)) {
                throw $exception;
            }
        }

        return [
            'diagnostics_available' => false,
            'health' => $this->health(),
            'metadata' => $this->metadata(),
        ];
    }

    public function flowBeaconPing(?string $organisationId = null): array
    {
        $headers = [];
        if ($organisationId !== null && $organisationId !== '') {
            $headers[] = 'X-Organisation-Id: ' . $organisationId;
        }

        return $this->request('GET', '/api/integration/flowbeacon/ping', headers: $headers);
    }

    /**
     * @return array<string, mixed>
     */
    public function flowScribeStatus(string $jobId): array
    {
        return $this->request('GET', '/api/integration/flowscribe/status/' . rawurlencode($jobId));
    }

    /**
     * @return array<string, mixed>
     */
    public function rcInvoiceStatus(string $jobId): array
    {
        return $this->request('GET', '/api/rc/invoice-ocr/status/' . rawurlencode($jobId));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function upload(string $path, string $filePath, array $options): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new FlowScribeOcrException(sprintf('Document file is not readable: %s', $filePath));
        }

        $fields = [
            'file' => new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', basename($filePath)),
        ];

        if (isset($options['document_type']) && $options['document_type'] !== '') {
            $documentType = (string) $options['document_type'];
            if ($documentType !== 'auto' || ($options['send_auto_document_type'] ?? false)) {
                $fields['document_type'] = $documentType;
            }
        }

        if (isset($options['mode']) && $options['mode'] !== '') {
            $fields['mode'] = (string) $options['mode'];
        }

        if (array_key_exists('journal_csv', $options)) {
            $fields['journal_csv'] = $this->booleanFormValue((bool) $options['journal_csv']);
        }

        foreach ([
            'source_document_id',
            'workspace_id',
            'org_id',
            'user_id',
            'context_type',
            'context_id',
            'rc_callback_url',
            'document_name',
        ] as $key) {
            if (isset($options[$key]) && $options[$key] !== '') {
                $fields[$key] = (string) $options[$key];
            }
        }

        foreach (['return_review_payload', 'include_storage', 'include_preview'] as $key) {
            if (array_key_exists($key, $options)) {
                $fields[$key] = $this->booleanFormValue((bool) $options[$key]);
            }
        }

        $headers = $this->headersForOptions($options);

        $temporaryConfigPath = null;
        if (isset($options['config_path']) && $options['config_path'] !== '') {
            $configPath = (string) $options['config_path'];
            if (!is_file($configPath) || !is_readable($configPath)) {
                throw new FlowScribeOcrException(sprintf('Config file is not readable: %s', $configPath));
            }
            $fields['config'] = new CURLFile($configPath, 'application/json', basename($configPath));
        } elseif (isset($options['config']) && is_array($options['config'])) {
            $temporaryConfigPath = tempnam(sys_get_temp_dir(), 'flowscribe-ocr-config-');
            if ($temporaryConfigPath === false) {
                throw new FlowScribeOcrException('Unable to create a temporary config file.');
            }
            file_put_contents($temporaryConfigPath, json_encode($options['config'], JSON_THROW_ON_ERROR));
            $fields['config'] = new CURLFile($temporaryConfigPath, 'application/json', 'config.json');
        }

        try {
            return $this->request('POST', $path, body: $fields, headers: $headers);
        } finally {
            if ($temporaryConfigPath !== null && is_file($temporaryConfigPath)) {
                unlink($temporaryConfigPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function reviewDefaults(array $options): array
    {
        return array_replace([
            'document_type' => 'auto',
            'mode' => 'fuse',
            'return_review_payload' => true,
            'include_storage' => true,
            'include_preview' => true,
        ], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function headersForOptions(array $options): array
    {
        $map = [
            'workspace_id' => 'X-Workspace-Id',
            'org_id' => 'X-Org-Id',
            'source_document_id' => 'X-Source-Document-Id',
            'correlation_id' => 'X-Correlation-Id',
            'idempotency_key' => 'Idempotency-Key',
            'rc_callback_url' => 'X-RC-Callback-Url',
        ];

        $headers = [];
        foreach ($map as $optionKey => $headerName) {
            if (isset($options[$optionKey]) && $options[$optionKey] !== '') {
                $headers[] = $headerName . ': ' . (string) $options[$optionKey];
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $handle = curl_init($this->url($path));
        if ($handle === false) {
            throw new FlowScribeOcrException('Unable to initialize cURL.');
        }

        $responseHeaders = [];
        $requestHeaders = array_merge(['Accept: application/json'], $headers);
        if ($this->accessToken !== null && $this->accessToken !== '') {
            $requestHeaders[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $message = curl_error($handle) ?: 'Unknown cURL error.';
            curl_close($handle);
            throw new FlowScribeOcrException($message);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $rawBody = (string) $raw;
        if ($statusCode < 200 || $statusCode >= 300) {
            $decoded = $this->tryDecodeJsonObject($rawBody) ?? [];
            $message = $this->extractErrorMessage($decoded) ?? sprintf('FlowScribe OCR API returned HTTP %d.', $statusCode);
            throw new FlowScribeOcrException(
                $message,
                $statusCode,
                $decoded,
                $rawBody,
                $this->extractHeader($responseHeaders, 'x-correlation-id'),
                $this->extractErrorCode($decoded)
            );
        }

        return $this->decodeJson($rawBody, $statusCode);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function booleanFormValue(bool $value): string
    {
        return $value ? 'true' : 'false';
    }


    /**
     * @return array<string, mixed>|null
     */
    private function tryDecodeJsonObject(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw, int $statusCode): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FlowScribeOcrException(
                sprintf('FlowScribe OCR API returned invalid JSON with HTTP %d.', $statusCode),
                $statusCode,
                rawResponseBody: $raw
            );
        }

        if (!is_array($decoded)) {
            throw new FlowScribeOcrException(
                sprintf('FlowScribe OCR API returned a non-object JSON payload with HTTP %d.', $statusCode),
                $statusCode,
                rawResponseBody: $raw
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     */
    private function extractHeader(array $headers, string $name): ?string
    {
        $value = $headers[strtolower($name)] ?? null;
        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractErrorCode(array $payload): ?string
    {
        foreach (['code', 'error_code', 'type'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            foreach (['code', 'type'] as $key) {
                if (isset($payload['error'][$key]) && is_string($payload['error'][$key]) && $payload['error'][$key] !== '') {
                    return $payload['error'][$key];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractErrorMessage(array $payload): ?string
    {
        foreach (['message', 'error', 'code'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        return null;
    }
}
