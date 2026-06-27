<?php

namespace Hansajith18\LaravelPaycorp\Exceptions;

use RuntimeException;

class PaycorpPaymentFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseText,
        public readonly ?string $txnReference = null,
    ) {
        parent::__construct("Payment failed [{$responseCode}]: {$responseText}");
    }
}
