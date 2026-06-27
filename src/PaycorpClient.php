<?php

namespace Hansajith18\LaravelPaycorp;

use Hansajith18\LaravelPaycorp\Contracts\PaycorpGatewayInterface;
use Hansajith18\LaravelPaycorp\Contracts\PaycorpRefundGatewayInterface;
use Hansajith18\LaravelPaycorp\DTOs\PaymentCompleteRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentCompleteResponse;
use Hansajith18\LaravelPaycorp\DTOs\PaymentInitRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentInitResponse;
use Hansajith18\LaravelPaycorp\DTOs\PaymentRealTimeRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentRealTimeResponse;
use Hansajith18\LaravelPaycorp\DTOs\RefundRequest;
use Hansajith18\LaravelPaycorp\DTOs\RefundResponse;
use Hansajith18\LaravelPaycorp\Enums\PaycorpTransactionType;
use Illuminate\Validation\ValidationException;

/**
 * Low-level Paycorp API client (gateway facade).
 *
 * This is the single place in the package that builds request DTOs, resolves
 * client IDs, and talks to the underlying gateways. It is stateless and does
 * NOT touch the database — use it directly when you want to drive the gateway
 * yourself, or use PaycenterPaymentService for a fully persisted, audited,
 * event-dispatching workflow built on top of this client.
 */
readonly class PaycorpClient
{
    public function __construct(
        private PaycorpGatewayInterface $gateway,
        private ?PaycorpRefundGatewayInterface $refundGateway = null,
    ) {}

    /**
     * Initialize a payment via Paycorp Hosted Page.
     *
     * @param  int  $amountCents  Amount in minor currency units (cents)
     * @param  string  $clientRef  Unique merchant reference for this payment
     * @param  string|null  $currency  ISO 4217 currency code (defaults to config)
     * @param  string  $comment  Optional merchant comment
     * @param  array  $extraData  Optional key-value metadata
     * @param  bool  $tokenize  Whether to tokenize (save) the card for later real-time charges
     * @param  string|null  $tokenReference  Optional reference to associate with the saved token
     */
    public function initPayment(
        int $amountCents,
        string $clientRef,
        string $returnUrl,
        ?string $currency = null,
        string $comment = '',
        array $extraData = [],
        PaycorpTransactionType $transactionType = PaycorpTransactionType::PURCHASE,
        bool $tokenize = false,
        ?string $tokenReference = null,
    ): PaymentInitResponse {
        $resolvedCurrency = $this->resolveCurrency($currency);

        $request = new PaymentInitRequest(
            clientId: $this->gateway->getClientId($resolvedCurrency),
            paymentAmountCents: $amountCents,
            currency: $resolvedCurrency,
            returnUrl: $returnUrl,
            clientRef: $clientRef,
            transactionType: $transactionType,
            tokenize: $tokenize,
            tokenReference: $tokenReference,
            comment: $comment,
            extraData: $extraData,
        );

        return $this->gateway->paymentInit($request);
    }

    /**
     * Complete a payment after payer returns from Paycorp hosted page.
     */
    public function completePayment(string $reqId, ?string $currency = null): PaymentCompleteResponse
    {
        $resolvedCurrency = $this->resolveCurrency($currency);

        $request = new PaymentCompleteRequest(
            clientId: $this->gateway->getClientId($resolvedCurrency),
            reqId: $reqId,
        );

        return $this->gateway->paymentComplete($request);
    }

    /**
     * Charge a previously tokenized (saved) card in real-time, without a
     * hosted-page redirect.
     *
     * @param  string  $cardToken  Paycorp CardVault token (never a raw card number)
     * @param  string  $cardExpiry  Card expiry in MMYY format
     * @param  int  $amountCents  Amount in minor currency units (cents)
     * @param  string  $clientRef  Unique merchant reference for this charge
     * @param  string|null  $currency  ISO 4217 currency code (defaults to config)
     * @param  string  $comment  Optional merchant comment
     * @param  array  $extraData  Optional key-value metadata
     */
    public function realTimePayment(
        string $cardToken,
        string $cardExpiry,
        int $amountCents,
        string $clientRef,
        ?string $currency = null,
        string $comment = '',
        array $extraData = [],
        PaycorpTransactionType $transactionType = PaycorpTransactionType::PURCHASE,
    ): PaymentRealTimeResponse {
        $resolvedCurrency = $this->resolveCurrency($currency);

        $request = new PaymentRealTimeRequest(
            clientId: $this->gateway->getClientId($resolvedCurrency, true),
            cardToken: $cardToken,
            cardExpiry: $cardExpiry,
            paymentAmountCents: $amountCents,
            currency: $resolvedCurrency,
            clientRef: $clientRef,
            transactionType: $transactionType,
            comment: $comment,
            extraData: $extraData,
        );

        return $this->gateway->paymentRealTime($request);
    }

    /**
     * Refund a previously completed transaction.
     *
     * @param  string  $originalTxnReference  The txnReference from the original purchase
     * @param  int  $amountCents  Refund amount in minor currency units (cents)
     * @param  string  $clientRef  Unique merchant reference for this refund
     * @param  string|null  $currency  ISO 4217 currency code (defaults to config)
     * @param  string  $comment  Optional comment/reason
     */
    public function refund(
        string $originalTxnReference,
        int $amountCents,
        string $clientRef,
        ?string $currency = null,
        string $comment = '',
    ): RefundResponse {
        return $this->processRefund($originalTxnReference, $amountCents, $clientRef, $currency, $comment);
    }

    /**
     * Void (reverse) a previously completed transaction.
     *
     * @param  string  $originalTxnReference  The txnReference from the original purchase
     * @param  int  $amountCents  Amount in minor currency units (cents)
     * @param  string  $clientRef  Unique merchant reference for this void
     * @param  string|null  $currency  ISO 4217 currency code (defaults to config)
     * @param  string  $comment  Optional comment/reason
     */
    public function void(
        string $originalTxnReference,
        int $amountCents,
        string $clientRef,
        ?string $currency = null,
        string $comment = '',
    ): RefundResponse {
        return $this->processRefund($originalTxnReference, $amountCents, $clientRef, $currency, $comment);
    }

    public function getGateway(): PaycorpGatewayInterface
    {
        return $this->gateway;
    }

    public function getRefundGateway(): ?PaycorpRefundGatewayInterface
    {
        return $this->refundGateway;
    }

    /**
     * Whether a refund/void gateway is configured and available.
     */
    public function isRefundConfigured(): bool
    {
        return $this->refundGateway !== null;
    }

    /**
     * The full request envelope sent during the most recent payment-gateway call.
     */
    public function getLastRequestPayload(): array
    {
        return $this->gateway->getLastRequestPayload();
    }

    /**
     * The full request envelope sent during the most recent refund-gateway call.
     */
    public function getLastRefundRequestPayload(): array
    {
        return $this->refundGateway?->getLastRequestPayload() ?? [];
    }

    /**
     * Build and dispatch a refund/void request (both use the REFUND transaction type).
     */
    private function processRefund(
        string $originalTxnReference,
        int $amountCents,
        string $clientRef,
        ?string $currency,
        string $comment,
    ): RefundResponse {
        $this->ensureRefundGateway();

        $resolvedCurrency = $this->resolveCurrency($currency);

        $request = new RefundRequest(
            clientId: $this->refundGateway->getClientId($resolvedCurrency),
            originalTxnReference: $originalTxnReference,
            transactionType: PaycorpTransactionType::REFUND,
            paymentAmountCents: $amountCents,
            currency: $resolvedCurrency,
            clientRef: $clientRef,
            comment: $comment,
        );

        return $this->refundGateway->refund($request);
    }

    private function resolveCurrency(?string $currency): string
    {
        return strtoupper($currency ?? config('paycorp.default_currency', 'LKR'));
    }

    private function ensureRefundGateway(): void
    {
        if ($this->refundGateway === null) {
            throw ValidationException::withMessages([
                'refund_gatway_config' => ['Refund gateway not configured. Set PAYCORP_REFUND_USERNAME, PAYCORP_REFUND_PASSWORD, PAYCORP_REFUND_IV_PHRASE and PAYCORP_REFUND_AES_SECRET environment variables.'],
            ]);
        }
    }
}
