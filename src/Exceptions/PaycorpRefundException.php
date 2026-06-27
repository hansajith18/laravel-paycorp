<?php

namespace Hansajith18\LaravelPaycorp\Exceptions;

use RuntimeException;

class PaycorpRefundException extends RuntimeException
{
    private ?array $rawResponse = null;

    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseText,
        public readonly ?string $originalTxnReference = null,
    ) {
        parent::__construct("Refund failed [{$responseCode}]: {$responseText}");
    }

    public static function loginFailed(string $reason): self
    {
        return new self(
            responseCode: 'LOGIN_FAILED',
            responseText: $reason,
        );
    }

    public function setRawResponse(?array $rawResponse): self
    {
        $this->rawResponse = $rawResponse;

        return $this;
    }

    public function getRawResponse(): ?array
    {
        return $this->rawResponse;
    }
}
