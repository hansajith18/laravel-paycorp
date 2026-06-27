<?php

namespace Hansajith18\LaravelPaycorp\Services;

use Hansajith18\LaravelPaycorp\Contracts\PaymentPurposeContract;
use Hansajith18\LaravelPaycorp\Enums\GatewayPaymentStatusEnum;
use Hansajith18\LaravelPaycorp\Enums\PaycorpResponseCode;
use Hansajith18\LaravelPaycorp\Enums\PaycorpTransactionType;
use Hansajith18\LaravelPaycorp\Events\PaymentFailed;
use Hansajith18\LaravelPaycorp\Events\PaymentSucceeded;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpConnectionException;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpInvalidResponseException;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpRefundException;
use Hansajith18\LaravelPaycorp\Jobs\VoidTokenizationCharge;
use Hansajith18\LaravelPaycorp\Logging\GatewayAuditLogger;
use Hansajith18\LaravelPaycorp\Logging\PaycorpLogger;
use Hansajith18\LaravelPaycorp\Models\Payment;
use Hansajith18\LaravelPaycorp\Models\PaymentGatewayLog;
use Hansajith18\LaravelPaycorp\Models\SavedPayment;
use Hansajith18\LaravelPaycorp\PaycorpClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * High-level payment orchestration built on top of {@see PaycorpClient}.
 *
 * Responsibilities that belong here (and NOT in PaycorpClient):
 *   - Payment model lifecycle (create / update / status transitions)
 *   - Idempotency and concurrency control for completion
 *   - Persisted, PCI-safe gateway audit logging (via GatewayAuditLogger)
 *   - Domain events (PaymentSucceeded / PaymentFailed) and queued jobs
 *   - Saved-card persistence and payable status propagation
 *
 * All raw gateway communication (DTO building, client-ID resolution, HTTP
 * calls) is delegated to PaycorpClient, so there is exactly one place that
 * speaks the Paycorp protocol.
 */
readonly class PaycenterPaymentService
{
    protected PaycorpLogger $logger;

    public function __construct(
        protected PaycorpClient $client,
        protected GatewayAuditLogger $audit,
        ?PaycorpLogger $logger = null,
    ) {
        $this->logger = $logger ?? new PaycorpLogger(app('log'), 'paycorp');
    }

    /**
     * Initialize a payment and return the Payment model with the hosted page URL.
     *
     * @param  \BackedEnum&PaymentPurposeContract  $purpose
     */
    public function initializePayment(
        int $userId,
        int $amountCents,
        \BackedEnum $purpose,
        string $currency = 'LKR',
        ?string $payableType = null,
        ?int $payableId = null,
        array $meta = [],
        PaycorpTransactionType $transactionType = PaycorpTransactionType::PURCHASE,
        bool $tokenize = false,
        ?string $tokenReference = null,
    ): Payment {
        $this->validatePayableType($payableType);

        $amountCents = $this->normalizeAmountCents($amountCents);
        $clientRef = $this->generateClientRef($purpose);

        $payment = Payment::create([
            'user_id' => $userId,
            'purpose' => $purpose->value,
            'status' => GatewayPaymentStatusEnum::PENDING,
            'gateway' => 'paycorp',
            'currency' => strtoupper($currency),
            'amount_cents' => $amountCents,
            'client_ref' => $clientRef,
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'meta' => $meta,
        ]);

        $startTime = microtime(true);

        try {
            $initResponse = $this->client->initPayment(
                amountCents: $amountCents,
                clientRef: $clientRef,
                returnUrl: config('paycorp.return_url'),
                currency: $currency,
                comment: $this->purposeLabel($purpose),
                extraData: ['payment_id' => (string) $payment->id],
                transactionType: $transactionType,
                tokenize: $tokenize,
                tokenReference: $tokenReference,
            );
            $durationMs = $this->elapsedMs($startTime);

            // Full envelope sent to Paycorp (includes version, msgId, operation, requestDate)
            $fullRequestPayload = $this->client->getLastRequestPayload();

            $this->logger->info('Paycorp PAYMENT_INIT request', [
                'operation' => 'PAYMENT_INIT',
                'payment_id' => $payment->id,
                'payload' => $fullRequestPayload,
            ]);

            $this->logger->info('Paycorp PAYMENT_INIT response', [
                'operation' => 'PAYMENT_INIT',
                'payment_id' => $payment->id,
                'response' => $initResponse->rawResponse,
            ]);

            $this->audit->logRequest(
                $payment,
                'PAYMENT_INIT',
                $this->audit->maskSensitiveRequestPayload($fullRequestPayload)
            );

            $this->audit->logResponse(
                $payment,
                'PAYMENT_INIT',
                200,
                [
                    'reqid' => $initResponse->reqId,
                    'paymentPageUrl' => '[REDACTED]',
                    'expireAt' => $initResponse->expireAt,
                ],
                $initResponse->rawResponse,
                $durationMs
            );

            $payment->update([
                'gateway_req_id' => $initResponse->reqId,
                'payment_page_url' => $initResponse->paymentPageUrl,
                'expire_at' => $initResponse->expireAt,
            ]);

            return $payment->fresh();

        } catch (PaycorpConnectionException|PaycorpInvalidResponseException $e) {
            $durationMs = $this->elapsedMs($startTime);

            // Still log the full request envelope even on failure
            $fullRequestPayload = $this->client->getLastRequestPayload();

            $this->logger->warning('Paycorp PAYMENT_INIT failed', [
                'operation' => 'PAYMENT_INIT',
                'payment_id' => $payment->id,
                'payload' => $fullRequestPayload,
                'error' => $e->getMessage(),
            ]);

            $this->audit->logRequest(
                $payment,
                'PAYMENT_INIT',
                $this->audit->maskSensitiveRequestPayload($fullRequestPayload)
            );

            $rawResponse = $e instanceof PaycorpInvalidResponseException ? $e->getRawResponse() : null;

            $this->audit->logResponse(
                $payment, 'PAYMENT_INIT', 0,
                ['error' => $e->getMessage()],
                $rawResponse,
                $durationMs
            );

            $payment->update(['status' => GatewayPaymentStatusEnum::FAILED]);

            throw $e;
        }
    }

    /**
     * Charge a previously tokenized (saved) card in real-time.
     *
     * @param  \BackedEnum&PaymentPurposeContract  $purpose
     */
    public function chargeWithSavedPayment(
        int $userId,
        int $amountCents,
        \BackedEnum $purpose,
        SavedPayment $savedPayment,
        string $currency = 'LKR',
        ?string $payableType = null,
        ?int $payableId = null,
        array $meta = [],
    ): Payment {
        $this->validatePayableType($payableType);

        $amountCents = $this->normalizeAmountCents($amountCents);
        $clientRef = $this->generateClientRef($purpose);

        $payment = Payment::create([
            'user_id' => $userId,
            'purpose' => $purpose->value,
            'status' => GatewayPaymentStatusEnum::PENDING,
            'gateway' => 'paycorp',
            'currency' => strtoupper($currency),
            'amount_cents' => $amountCents,
            'client_ref' => $clientRef,
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'meta' => array_merge($meta, [
                'saved_payment_id' => $savedPayment->id,
                'payment_method' => 'saved_card',
            ]),
        ]);

        $startTime = microtime(true);

        try {
            $response = $this->client->realTimePayment(
                cardToken: $savedPayment->token,
                cardExpiry: $savedPayment->card_expiry ?? '',
                amountCents: $amountCents,
                clientRef: $clientRef,
                currency: $currency,
                comment: $this->purposeLabel($purpose),
                extraData: ['payment_id' => (string) $payment->id],
            );
            $durationMs = $this->elapsedMs($startTime);

            $fullRequestPayload = $this->client->getLastRequestPayload();

            $this->logger->info('Paycorp PAYMENT_REAL_TIME request', [
                'operation' => 'PAYMENT_REAL_TIME',
                'payment_id' => $payment->id,
                'payload' => $fullRequestPayload,
            ]);

            $this->logger->info('Paycorp PAYMENT_REAL_TIME response', [
                'operation' => 'PAYMENT_REAL_TIME',
                'payment_id' => $payment->id,
                'response' => $response->rawResponse,
            ]);

            $this->audit->logRequest(
                $payment,
                'PAYMENT_REAL_TIME',
                $this->audit->maskSensitiveRequestPayload($fullRequestPayload)
            );

            $this->audit->logResponse(
                $payment,
                'PAYMENT_REAL_TIME',
                200,
                $response->toSafeArray(),
                $response->rawResponse,
                $durationMs
            );

            return DB::transaction(function () use ($payment, $response) {
                $isApproved = $response->isApproved();

                $payment->update([
                    'status' => $isApproved ? GatewayPaymentStatusEnum::COMPLETED : GatewayPaymentStatusEnum::FAILED,
                    'gateway_txn_reference' => $response->txnReference,
                    'gateway_response_code' => $response->responseCode,
                    'gateway_response_text' => $response->responseText,
                    'gateway_auth_code' => $response->authCode,
                    'card_type' => $response->cardType,
                    'masked_card_number' => $response->maskedCardNumber,
                    'card_last4' => $response->cardLast4(),
                    'completed_at' => $isApproved ? now() : null,
                ]);

                $payment = $payment->fresh();

                $this->updatePayableStatus($payment, $isApproved);

                if ($isApproved) {
                    PaymentSucceeded::dispatch($payment);
                } else {
                    PaymentFailed::dispatch($payment);
                }

                return $payment;
            });

        } catch (PaycorpConnectionException|PaycorpInvalidResponseException $e) {
            $durationMs = $this->elapsedMs($startTime);

            $fullRequestPayload = $this->client->getLastRequestPayload();

            $this->logger->warning('Paycorp PAYMENT_REAL_TIME failed', [
                'operation' => 'PAYMENT_REAL_TIME',
                'payment_id' => $payment->id,
                'payload' => $fullRequestPayload,
                'error' => $e->getMessage(),
            ]);

            $this->audit->logRequest(
                $payment,
                'PAYMENT_REAL_TIME',
                $this->audit->maskSensitiveRequestPayload($fullRequestPayload)
            );

            $rawResponse = $e instanceof PaycorpInvalidResponseException ? $e->getRawResponse() : null;

            $this->audit->logResponse(
                $payment, 'PAYMENT_REAL_TIME', 0,
                ['error' => $e->getMessage()],
                $rawResponse,
                $durationMs
            );

            $payment->update(['status' => GatewayPaymentStatusEnum::FAILED]);
            PaymentFailed::dispatch($payment->fresh());

            throw $e;
        }
    }

    /**
     * Complete a payment after the payer returns from the hosted page.
     * Idempotent: only one PAYMENT_COMPLETE call is ever sent to the gateway per reqId.
     */
    public function completePayment(string $reqId): Payment
    {
        $payment = Payment::where('gateway_req_id', $reqId)->firstOrFail();

        $payment = $payment->fresh(); // always fetch the very latest status from DB
        if ($payment->status === GatewayPaymentStatusEnum::AWAITING_CAPTURE || $payment->isFinal()) {
            return $payment;
        }

        $alreadyRequested = PaymentGatewayLog::where('payment_id', $payment->id)
            ->where('operation', 'PAYMENT_COMPLETE')
            ->where('direction', 'request')
            ->exists();

        if ($alreadyRequested) {
            $this->logger->warning('completePayment: PAYMENT_COMPLETE already logged for this payment — aborting duplicate call', [
                'operation' => 'PAYMENT_COMPLETE',
                'payment_id' => $payment->id,
                'status' => $payment->status->value,
            ]);

            return $payment;
        }

        // Atomic lock: prevent concurrent PAYMENT_COMPLETE requests.
        $locked = DB::transaction(function () use ($payment) {
            $fresh = Payment::lockForUpdate()->find($payment->id);

            if ($fresh->status !== GatewayPaymentStatusEnum::PENDING) {
                return null;
            }

            $fresh->update(['status' => GatewayPaymentStatusEnum::AWAITING_CAPTURE]);

            return $fresh;
        });

        if ($locked === null) {
            return $payment->fresh();
        }

        $payment = $locked;

        if (empty($payment->client_ref)) {
            $payment->update(['status' => GatewayPaymentStatusEnum::FAILED]);
            PaymentFailed::dispatch($payment->fresh());

            return $payment->fresh();
        }

        $startTime = microtime(true);

        try {
            $response = $this->client->completePayment($reqId, $payment->currency);
            $durationMs = $this->elapsedMs($startTime);

            $fullRequestPayload = $this->client->getLastRequestPayload();

            $this->audit->logRequest(
                $payment,
                'PAYMENT_COMPLETE',
                $fullRequestPayload
            );

            $this->audit->logResponse(
                $payment,
                'PAYMENT_COMPLETE',
                200,
                $response->toSafeArray(),
                $response->rawResponse,
                $durationMs
            );

            if ($response->clientRef !== $payment->client_ref) {
                $this->logger->error('Payment clientRef mismatch', [
                    'operation' => 'PAYMENT_COMPLETE',
                    'payment_id' => $payment->id,
                    'expected' => $payment->client_ref,
                    'received' => $response->clientRef,
                ]);

                $payment->update(['status' => GatewayPaymentStatusEnum::FAILED]);
                PaymentFailed::dispatch($payment->fresh());

                return $payment->fresh();
            }

            $payment = DB::transaction(function () use ($payment, $response) {
                $isApproved = $response->responseCode === PaycorpResponseCode::APPROVED->value;

                $payment->update([
                    'status' => $isApproved ? GatewayPaymentStatusEnum::COMPLETED : GatewayPaymentStatusEnum::FAILED,
                    'gateway_txn_reference' => $response->txnReference,
                    'gateway_response_code' => $response->responseCode,
                    'gateway_response_text' => $response->responseText,
                    'gateway_auth_code' => $response->authCode,
                    'card_type' => $response->cardType,
                    'masked_card_number' => $response->maskedCardNumber,
                    'card_last4' => $response->cardLast4(),
                    'completed_at' => $isApproved ? now() : null,
                ]);

                if ($isApproved && $response->token) {
                    SavedPayment::updateOrCreate([
                        'user_id' => $payment->user_id,
                        'token' => $response->token,
                    ], [
                        'gateway' => 'paycorp',
                        'card_type' => $response->cardType,
                        'masked_card_number' => $response->maskedCardNumber,
                        'card_last4' => $response->cardLast4(),
                        'card_expiry' => $response->cardExpiry,
                        'card_holder_name' => $response->cardHolderName,
                        'token_reference' => $response->tokenReference,
                    ]);
                }

                $payment = $payment->fresh();

                $this->updatePayableStatus($payment, $isApproved);

                return $payment;
            });

            // Dispatch events and jobs AFTER the transaction is committed.
            if ($payment->isCompleted()) {
                PaymentSucceeded::dispatch($payment);

                $cardRegistrationValue = config('paycorp.card_registration_purpose', 'card_registration');
                if ($payment->getRawOriginal('purpose') === $cardRegistrationValue) {
                    VoidTokenizationCharge::dispatch($payment->id);
                }
            } else {
                PaymentFailed::dispatch($payment);
            }

            return $payment;

        } catch (PaycorpConnectionException|PaycorpInvalidResponseException $e) {
            $durationMs = $this->elapsedMs($startTime);

            $fullRequestPayload = $this->client->getLastRequestPayload();

            $this->logger->error('Paycorp PAYMENT_COMPLETE — gateway communication error', [
                'operation' => 'PAYMENT_COMPLETE',
                'payment_id' => $payment->id,
                'error_class' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            if (! empty($fullRequestPayload)) {
                $this->audit->logRequest($payment, 'PAYMENT_COMPLETE', $fullRequestPayload);
            }

            $rawResponse = $e instanceof PaycorpInvalidResponseException ? $e->getRawResponse() : null;

            $this->audit->logResponse(
                $payment,
                'PAYMENT_COMPLETE',
                0,
                ['error' => $e->getMessage(), 'error_class' => get_class($e)],
                $rawResponse,
                $durationMs,
            );

            throw $e;
        }
    }

    /**
     * Void (reverse) a completed payment.
     */
    public function voidPayment(Payment $payment): void
    {
        if (! $this->client->isRefundConfigured()) {
            $this->logger->error('PaymentService::voidPayment — refund gateway not configured', [
                'operation' => 'PAYMENT_VOID',
                'payment_id' => $payment->id,
            ]);
            throw PaycorpRefundException::loginFailed('Refund gateway not configured.');
        }

        $clientRef = $payment->client_ref . '-VOID';
        $startTime = microtime(true);

        try {
            $voidResponse = $this->client->void(
                originalTxnReference: $payment->gateway_txn_reference,
                amountCents: $payment->amount_cents,
                clientRef: $clientRef,
                currency: $payment->currency,
                comment: "Auto-void card registration charge for payment #{$payment->id}",
            );
            $durationMs = $this->elapsedMs($startTime);

            $fullRequestPayload = $this->client->getLastRefundRequestPayload();

            $this->logger->info('Paycorp PAYMENT_VOID request', [
                'operation' => 'PAYMENT_VOID',
                'payment_id' => $payment->id,
                'payload' => $fullRequestPayload,
            ]);

            $this->logger->info('Paycorp PAYMENT_VOID response', [
                'operation' => 'PAYMENT_VOID',
                'payment_id' => $payment->id,
                'response' => $voidResponse->rawResponse,
            ]);

            $this->audit->logRequest($payment, 'PAYMENT_VOID', $fullRequestPayload);
            $this->audit->logResponse(
                $payment,
                'PAYMENT_VOID',
                $voidResponse->isApproved() ? 200 : 0,
                $voidResponse->toSafeArray(),
                $voidResponse->rawResponse,
                $durationMs,
            );

            if ($voidResponse->isApproved()) {
                $payment->update([
                    'status' => GatewayPaymentStatusEnum::VOIDED,
                    'meta' => array_merge($payment->meta ?? [], [
                        'void_txn_reference' => $voidResponse->txnReference,
                        'void_response_code' => $voidResponse->responseCode,
                        'voided_at' => now()->toIso8601String(),
                    ]),
                ]);

                $this->logger->info('PaymentService::voidPayment — void successful', [
                    'operation' => 'PAYMENT_VOID',
                    'payment_id' => $payment->id,
                    'void_txn_reference' => $voidResponse->txnReference,
                    'original_txn_ref' => $payment->gateway_txn_reference,
                ]);
            } else {
                $this->logger->warning('PaymentService::voidPayment — void declined by gateway', [
                    'operation' => 'PAYMENT_VOID',
                    'payment_id' => $payment->id,
                    'response_code' => $voidResponse->responseCode,
                    'response_text' => $voidResponse->responseText,
                    'original_txn' => $payment->gateway_txn_reference,
                ]);

                throw new PaycorpRefundException(
                    $voidResponse->responseCode,
                    $voidResponse->responseText,
                    $payment->gateway_txn_reference
                );
            }

        } catch (\Throwable $e) {
            $durationMs = $this->elapsedMs($startTime);

            $lastPayload = $this->client->getLastRefundRequestPayload();

            $this->logger->error('PaymentService::voidPayment — gateway communication error', [
                'operation' => 'PAYMENT_VOID',
                'payment_id' => $payment->id,
                'error_class' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            if (! empty($lastPayload)) {
                $this->logger->info('Paycorp PAYMENT_VOID request (captured before communication error)', [
                    'operation' => 'PAYMENT_VOID',
                    'payment_id' => $payment->id,
                    'payload' => $lastPayload,
                ]);
                $this->audit->logRequest($payment, 'PAYMENT_VOID', $lastPayload);
            }

            $rawResponse = null;
            if ($e instanceof PaycorpInvalidResponseException) {
                $rawResponse = $e->getRawResponse();
            } elseif ($e instanceof PaycorpRefundException) {
                $rawResponse = $e->getRawResponse();
            }

            $this->audit->logResponse(
                $payment,
                'PAYMENT_VOID',
                0,
                ['error' => $e->getMessage(), 'error_class' => get_class($e)],
                $rawResponse,
                $durationMs,
            );

            throw $e;
        }
    }

    /**
     * Returns true when the refund/void gateway is configured and available.
     */
    public function isRefundGatewayAvailable(): bool
    {
        return $this->client->isRefundConfigured();
    }

    /**
     * Get payment status for the API.
     */
    public function getPaymentStatus(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'purpose' => $payment->purpose->value,
            'purpose_label' => $payment->purpose->label(),
            'currency' => $payment->currency,
            'amount' => number_format($payment->amount_cents / 100, 2),
            'amount_cents' => $payment->amount_cents,
            'gateway_txn_reference' => $payment->gateway_txn_reference,
            'card_type' => $payment->card_type,
            'card_last4' => $payment->card_last4,
            'completed_at' => $payment->completed_at?->toIso8601String(),
            'created_at' => $payment->created_at->toIso8601String(),
        ];
    }

    private function updatePayableStatus(Payment $payment, bool $isApproved): void
    {
        $payable = $payment->payable;

        if (! $payable) {
            return;
        }

        $allowedPayables = config('paycorp.payable_status_classes', []);

        if (! in_array(class_basename($payable), $allowedPayables, true)) {
            return;
        }

        $statusColumn = config('paycorp.payable_status_column', 'payment_status');

        if (! Schema::hasColumn($payable->getTable(), $statusColumn)) {
            $this->logger->warning('Payable status column not found.', [
                'table' => $payable->getTable(),
                'column' => $statusColumn,
                'payable_type' => $payable::class,
            ]);

            return;
        }

        $payable->update([
            $statusColumn => $isApproved ? 'paid' : 'failed',
        ]);
    }

    /**
     * Normalize an amount for the configured gateway environment.
     *
     * In sandbox mode the Paycorp test IPG rejects sub-unit (decimal LKR)
     * amounts and requires a minimum of 200 cents (2.00 LKR). This method
     * enforces both constraints so no caller needs to know about them.
     *
     * In production the value is returned unchanged.
     */
    private function normalizeAmountCents(int $amountCents): int
    {
        if (! config('paycorp.sandbox', false)) {
            return $amountCents;
        }

        // Strip sub-unit cents: 538920 → 538900 (floor to nearest whole LKR).
        $normalized = $amountCents - ($amountCents % 100);

        // Enforce sandbox minimum of 200 cents (2.00 LKR).
        return max($normalized, 200);
    }

    /**
     * Derive a short uppercase prefix from the enum's string value and build a
     * unique client reference. Works with any BackedEnum regardless of cases.
     *
     * e.g. 'card_registration' → 'CARD', 'order_payment' → 'ORDE'
     */
    protected function generateClientRef(\BackedEnum $purpose): string
    {
        $prefix = strtoupper(substr(str_replace('_', '', (string) $purpose->value), 0, 4));

        return $prefix . '-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
    }

    /**
     * Return the human-readable label from the purpose enum.
     * Falls back to the raw value string when the enum does not implement
     * PaymentPurposeContract (i.e. has no label() method).
     */
    protected function purposeLabel(\BackedEnum $purpose): string
    {
        return $purpose instanceof PaymentPurposeContract
            ? $purpose->label()
            : (string) $purpose->value;
    }

    /**
     * Ensure the given payable_type class actually exists before persisting it,
     * so Eloquent's morphTo() never throws "Class not found" at runtime.
     */
    protected function validatePayableType(?string $payableType): void
    {
        if ($payableType !== null && ! class_exists($payableType)) {
            throw ValidationException::withMessages([
                'payable_type' => ["Paycorp: payable_type [{$payableType}] does not exist. " .
                'Pass a fully-qualified class name (e.g. App\\Models\\Order).'],
            ]);
        }
    }

    /**
     * Elapsed time in milliseconds since the given microtime(true) start.
     */
    private function elapsedMs(float $startTime): int
    {
        return (int) ((microtime(true) - $startTime) * 1000);
    }
}
