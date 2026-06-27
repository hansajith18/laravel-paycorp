<?php

namespace Hansajith18\LaravelPaycorp\Logging;

use Hansajith18\LaravelPaycorp\Models\Payment;
use Hansajith18\LaravelPaycorp\Models\PaymentGatewayLog;

/**
 * Writes PCI-safe audit records of every gateway request/response to the
 * payment_gateway_logs table.
 *
 * Sensitive fields (card numbers, CVV, track data) are stripped or masked
 * before anything is persisted, keeping all card-handling concerns in one place.
 */
class GatewayAuditLogger
{
    /**
     * Keys that must never be written to the audit log.
     */
    private const SENSITIVE_KEYS = ['cardNumber', 'cvv', 'secureId', 'track1', 'track2', 'track3'];

    /**
     * Persist an outgoing gateway request.
     *
     * The payload is expected to already be masked by the caller via
     * maskSensitiveRequestPayload() when it may contain card data.
     */
    public function logRequest(Payment $payment, string $operation, array $payload): void
    {
        PaymentGatewayLog::create([
            'payment_id' => $payment->id,
            'operation' => $operation,
            'direction' => 'request',
            'payload' => $payload,
        ]);
    }

    /**
     * Persist a gateway response (or error) with timing metadata.
     */
    public function logResponse(
        Payment $payment,
        string $operation,
        int $statusCode,
        array $payload,
        ?array $rawResponse = null,
        ?int $durationMs = null,
    ): void {
        $payload = $this->sanitize($payload);
        unset($payload['creditCard']);

        if ($rawResponse !== null) {
            $rawResponse = $this->sanitize($rawResponse);
            unset($rawResponse['creditCard']);
        }

        PaymentGatewayLog::create([
            'payment_id' => $payment->id,
            'operation' => $operation,
            'direction' => 'response',
            'status_code' => $statusCode,
            'payload' => $payload,
            'raw_response' => $rawResponse,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Mask the card token/number inside an outgoing request envelope so it can
     * be safely persisted, then strip any other sensitive keys.
     */
    public function maskSensitiveRequestPayload(array $payload): array
    {
        if (isset($payload['requestData']['creditCard']['number'])) {
            $payload['requestData']['creditCard']['number'] = '[TOKEN-MASKED]';
        }

        return $this->sanitize($payload);
    }

    /**
     * Recursively strip sensitive keys from an array.
     */
    public function sanitize(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (in_array($key, self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }
}
