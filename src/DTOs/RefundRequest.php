<?php

namespace Hansajith18\LaravelPaycorp\DTOs;

use Hansajith18\LaravelPaycorp\Enums\PaycorpTransactionType;

final readonly class RefundRequest
{
    public function __construct(
        public int $clientId,
        public string $originalTxnReference,
        public PaycorpTransactionType $transactionType,
        public int $paymentAmountCents,
        public string $currency,
        public string $clientRef,
        public string $comment = '',
    ) {}

    public function toRequestData(): array
    {
        $data = [
            'clientId' => $this->clientId,
            'originalTxnReference' => $this->originalTxnReference,
            'transactionType' => $this->transactionType->value,
            'transactionAmount' => [
                'totalAmount' => 0,
                'paymentAmount' => $this->paymentAmountCents,
                'serviceFeeAmount' => 0,
                'withholdingAmount' => 0,
                'currency' => $this->currency,
            ],
            'clientRef' => $this->clientRef,
        ];

        if ($this->comment !== '') {
            $data['comment'] = $this->comment;
        }

        return $data;
    }
}
