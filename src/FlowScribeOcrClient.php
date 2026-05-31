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
     * - document_type: string dictionary name from metadata()
     * - mode: "fuse" or "split"
     * - journal_csv: bool
     * - config_path: string path to a JSON dictionary override file
     * - config: array<string, mixed> dictionary override; sent as a generated JSON file
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
     * @return array<string, mixed>
     */
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

        foreach (['document_type', 'mode'] as $key) {
            if (isset($options[$key]) && $options[$key] !== '') {
                $fields[$key] = (string) $options[$key];
            }
        }

        if (array_key_exists('journal_csv', $options)) {
            $fields['journal_csv'] = $this->booleanFormValue((bool) $options['journal_csv']);
        }

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
            return $this->request('POST', $path, body: $fields);
        } finally {
            if ($temporaryConfigPath !== null && is_file($temporaryConfigPath)) {
                unlink($temporaryConfigPath);
            }
        }
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

        $decoded = $this->decodeJson((string) $raw, $statusCode);
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $this->extractErrorMessage($decoded) ?? sprintf('FlowScribe OCR API returned HTTP %d.', $statusCode);
            throw new FlowScribeOcrException($message, $statusCode, $decoded);
        }

        return $decoded;
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
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw, int $statusCode): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FlowScribeOcrException(
                sprintf('FlowScribe OCR API returned invalid JSON with HTTP %d.', $statusCode),
                $statusCode
            );
        }

        if (!is_array($decoded)) {
            throw new FlowScribeOcrException(
                sprintf('FlowScribe OCR API returned a non-object JSON payload with HTTP %d.', $statusCode),
                $statusCode
            );
        }

        return $decoded;
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
