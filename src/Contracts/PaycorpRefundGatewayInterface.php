<?php

namespace Hansajith18\LaravelPaycorp\Contracts;

use Hansajith18\LaravelPaycorp\DTOs\RefundRequest;
use Hansajith18\LaravelPaycorp\DTOs\RefundResponse;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpConnectionException;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpInvalidResponseException;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpRefundException;

interface PaycorpRefundGatewayInterface
{
    /**
     * Process a refund or void (reversal) against an original transaction.
     *
     * @throws PaycorpConnectionException
     * @throws PaycorpInvalidResponseException
     * @throws PaycorpRefundException
     */
    public function refund(RefundRequest $request): RefundResponse;

    /**
     * Returns the full request payload sent in the most recent gateway call.
     */
    public function getLastRequestPayload(): array;

    /**
     * Get the client ID for a given currency.
     */
    public function getClientId(?string $currency = null): int;
}
