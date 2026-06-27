<?php

namespace Hansajith18\LaravelPaycorp\Contracts;

use Hansajith18\LaravelPaycorp\DTOs\PaymentCompleteRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentCompleteResponse;
use Hansajith18\LaravelPaycorp\DTOs\PaymentInitRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentInitResponse;
use Hansajith18\LaravelPaycorp\DTOs\PaymentRealTimeRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentRealTimeResponse;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpConnectionException;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpInvalidResponseException;

interface PaycorpGatewayInterface
{
    /**
     * Initialize a payment and receive a hosted payment page URL.
     *
     * @throws PaycorpConnectionException
     * @throws PaycorpInvalidResponseException
     */
    public function paymentInit(PaymentInitRequest $request): PaymentInitResponse;

    /**
     * Complete a payment after the payer returns from the hosted page.
     *
     * @throws PaycorpConnectionException
     * @throws PaycorpInvalidResponseException
     */
    public function paymentComplete(PaymentCompleteRequest $request): PaymentCompleteResponse;

    /**
     * Charge a previously tokenized card in real-time (no hosted page redirect).
     *
     * This should ONLY be called with a Paycorp CardVault token — never a raw
     * card number — to remain PCI-compliant.
     *
     * @throws PaycorpConnectionException
     * @throws PaycorpInvalidResponseException
     */
    public function paymentRealTime(PaymentRealTimeRequest $request): PaymentRealTimeResponse;

    /**
     * Returns the full Paycorp request envelope (version/msgId/operation/
     * requestDate/requestData) that was sent in the most recent gateway call.
     *
     * The value is set before the HTTP round-trip, so it is available even
     * when the call throws a PaycorpConnectionException.
     */
    public function getLastRequestPayload(): array;

    /**
     * Get the client ID for a given currency.
     */
    public function getClientId(?string $currency = null, bool $isTokenized = false): int;
}
