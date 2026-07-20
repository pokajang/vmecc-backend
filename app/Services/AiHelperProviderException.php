<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class AiHelperProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerRequestId = null,
        public readonly string $stage = 'response',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /** @return array<string, bool|int|string|null> */
    public function context(): array
    {
        return [
            'failure_code' => $this->failureCode,
            'retryable' => $this->retryable,
            'http_status' => $this->httpStatus,
            'provider_request_id' => $this->providerRequestId,
            'stage' => $this->stage,
        ];
    }
}
