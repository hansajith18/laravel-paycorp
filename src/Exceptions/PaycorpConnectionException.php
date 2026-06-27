<?php

namespace Hansajith18\LaravelPaycorp\Exceptions;

use RuntimeException;

class PaycorpConnectionException extends RuntimeException
{
    public static function timeout(string $operation): self
    {
        return new self("Paycorp API connection timed out during {$operation}.");
    }

    public static function networkError(string $operation, string $reason): self
    {
        return new self("Paycorp API network error during {$operation}: {$reason}");
    }
}
