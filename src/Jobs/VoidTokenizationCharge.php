<?php

namespace Hansajith18\LaravelPaycorp\Jobs;

use Hansajith18\LaravelPaycorp\Enums\GatewayPaymentStatusEnum;
use Hansajith18\LaravelPaycorp\Logging\PaycorpLogger;
use Hansajith18\LaravelPaycorp\Models\Payment;
use Hansajith18\LaravelPaycorp\Services\PaycenterPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Silently voids (reverses) the small verification charge that Paycorp imposes
 * during card tokenisation.
 *
 * IMPORTANT — failure contract
 * ─────────────────────────────
 * The card-registration transaction is ALREADY committed (token saved, user notified)
 * before this job runs.  A void failure is therefore a purely internal concern and
 * MUST NOT surface to the user or re-open the card-registration record.
 */
class VoidTokenizationCharge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(
        private readonly int $paymentId,
    ) {
        $this->afterCommit = true;
    }

    public function handle(PaycenterPaymentService $paymentService, PaycorpLogger $logger): void
    {
        $payment = Payment::find($this->paymentId);

        if (! $payment) {
            $logger->warning('VoidTokenizationCharge: payment not found — aborting', [
                'operation' => 'PAYMENT_VOID',
                'payment_id' => $this->paymentId,
            ]);

            return;
        }

        if (! $payment->gateway_txn_reference) {
            $logger->warning('VoidTokenizationCharge: no gateway_txn_reference — aborting', [
                'operation' => 'PAYMENT_VOID',
                'payment_id' => $payment->id,
            ]);

            return;
        }

        // Idempotency: skip if a previous attempt already succeeded.
        if ($payment->status === GatewayPaymentStatusEnum::VOIDED) {
            $logger->info('VoidTokenizationCharge: payment already voided — skipping', [
                'operation' => 'PAYMENT_VOID',
                'payment_id' => $payment->id,
            ]);

            return;
        }

        // Pre-flight: if the refund gateway is not configured (missing credentials)
        // there is no point retrying — a config issue won't resolve itself.
        if (! $paymentService->isRefundGatewayAvailable()) {
            $logger->error('VoidTokenizationCharge: refund gateway not configured — aborting without retry', [
                'operation' => 'PAYMENT_VOID',
                'payment_id' => $payment->id,
                'hint' => 'Set PAYCORP_REFUND_USERNAME and PAYCORP_REFUND_PASSWORD in .env',
            ]);

            $payment->update([
                'meta' => array_merge($payment->meta ?? [], [
                    'void_skipped_no_gateway' => true,
                    'void_skipped_at' => now()->toIso8601String(),
                ]),
            ]);

            return; // Do NOT retry — silently abort
        }

        $attempt = $this->attempts();

        $logger->info('VoidTokenizationCharge: initiating void', [
            'operation' => 'PAYMENT_VOID',
            'payment_id' => $payment->id,
            'attempt' => $attempt,
            'max_tries' => $this->tries,
            'original_txn_ref' => $payment->gateway_txn_reference,
            'amount_cents' => $payment->amount_cents,
            'currency' => $payment->currency,
        ]);

        try {
            // Delegates to PaymentService which handles all gateway logging
            // (real request payload + raw response → payment_gateway_logs).
            $paymentService->voidPayment($payment);

            $payment->refresh();
            $payment->update([
                'meta' => array_merge($payment->meta ?? [], [
                    'void_attempts' => $attempt,
                    'void_succeeded_at' => now()->toIso8601String(),
                ]),
            ]);

        } catch (\Throwable $e) {
            $payment->refresh();

            $payment->update([
                'meta' => array_merge($payment->meta ?? [], [
                    'void_attempts' => $attempt,
                    'void_last_error' => $e->getMessage(),
                    'void_last_attempted_at' => now()->toIso8601String(),
                ]),
            ]);

            $logger->warning('VoidTokenizationCharge: attempt failed', [
                'operation' => 'PAYMENT_VOID',
                'payment_id' => $payment->id,
                'attempt' => $attempt,
                'max_tries' => $this->tries,
                'error_class' => get_class($e),
                'error' => $e->getMessage(),
                'queue_driver' => config('queue.default'),
            ]);

            if (config('queue.default') !== 'sync') {
                throw $e;
            }
        }
    }

    /**
     * Called automatically by the queue worker when all retry attempts are exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        $payment = Payment::find($this->paymentId);

        $logger = app(PaycorpLogger::class);

        if (! $payment) {
            $logger->error('VoidTokenizationCharge::failed — payment not found for final failure handling', [
                'operation' => 'PAYMENT_VOID',
                'payment_id' => $this->paymentId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $payment->update([
            'meta' => array_merge($payment->meta ?? [], [
                'void_failed_permanently' => true,
                'void_total_attempts' => $this->tries,
                'void_failure_reason' => $exception->getMessage(),
                'void_permanently_failed_at' => now()->toIso8601String(),
            ]),
        ]);

        $logger->error('VoidTokenizationCharge: permanently failed after all retry attempts — manual intervention required', [
            'operation' => 'PAYMENT_VOID',
            'payment_id' => $this->paymentId,
            'original_txn_ref' => $payment->gateway_txn_reference,
            'total_attempts' => $this->tries,
            'final_error' => $exception->getMessage(),
            'note' => 'Card registration succeeded. Only the reversal failed.',
        ]);
    }
}
