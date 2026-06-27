<?php

namespace Hansajith18\LaravelPaycorp\DTOs;

use Hansajith18\LaravelPaycorp\Enums\PaycorpTransactionType;

/**
 * Request DTO for PAYMENT_REAL_TIME operation.
 *
 * This is used to charge a previously tokenized card directly from the backend
 * without requiring the customer to go through a hosted payment page.
 * The `cardToken` field must be a Paycorp CardVault token (not a raw card number).
 *
 * Reference: Paycenter Technical Guide — PAYMENT_REAL_TIME operation.
 */
final readonly class PaymentRealTimeRequest
{
    public function __construct(
        public int $clientId,
        public string $cardToken,
        public string $cardExpiry,
        public int $paymentAmountCents,
        public string $currency,
        public string $clientRef,
        public PaycorpTransactionType $transactionType = PaycorpTransactionType::PURCHASE,
        public string $comment = '',
        public array $extraData = [],
    ) {}

    public function toRequestData(): array
    {
        $data = [
            'clientId' => $this->clientId,
            'creditCard' => [
                'number' => $this->cardToken,
                'expiry' => $this->cardExpiry,
            ],
            'transactionType' => $this->transactionType->value,
            'transactionAmount' => [
                'paymentAmount' => $this->paymentAmountCents,
                'currency' => $this->currency,
            ],
            'clientRef' => $this->clientRef,
        ];

        if ($this->comment !== '') {
            $data['comment'] = $this->comment;
        }

        if (! empty($this->extraData)) {
            $data['extraData'] = $this->extraData;
        }

        return $data;
    }
}
