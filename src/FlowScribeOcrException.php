<?php

namespace Robo\FlowScribeOcr;

use RuntimeException;

final class FlowScribeOcrException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?array $responseBody = null,
        private readonly ?string $rawResponseBody = null,
        private readonly ?string $correlationId = null,
        private readonly ?string $errorCode = null
    ) {
        parent::__construct($message, $statusCode ?? 0);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }

    public function getRawResponseBody(): ?string
    {
        return $this->rawResponseBody;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
