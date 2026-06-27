<?php

namespace Hansajith18\LaravelPaycorp\Exceptions;

use RuntimeException;

class PaycorpInvalidResponseException extends RuntimeException
{
    private ?array $rawResponse = null;

    public function setRawResponse(?array $rawResponse): self
    {
        $this->rawResponse = $rawResponse;

        return $this;
    }

    public function getRawResponse(): ?array
    {
        return $this->rawResponse;
    }

    public static function malformedJson(string $operation): self
    {
        return new self("Paycorp returned invalid JSON for {$operation}.");
    }

    public static function missingField(string $operation, string $field): self
    {
        return new self("Paycorp response for {$operation} missing required field: {$field}.");
    }

    public static function unexpectedStatus(string $operation, int $httpStatus): self
    {
        return new self("Paycorp returned HTTP {$httpStatus} for {$operation}.");
    }
}
