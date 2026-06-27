<?php

namespace Hansajith18\LaravelPaycorp\DTOs;

use Hansajith18\LaravelPaycorp\Enums\PaycorpTransactionType;

final readonly class PaymentInitRequest
{
    public function __construct(
        public int $clientId,
        public int $paymentAmountCents,
        public string $currency,
        public string $returnUrl,
        public string $clientRef,
        public PaycorpTransactionType $transactionType = PaycorpTransactionType::PURCHASE,
        public bool $tokenize = false,
        public ?string $tokenReference = null,
        public string $comment = '',
        public array $extraData = [],
    ) {}

    public function toRequestData(): array
    {
        $data = [
            'clientId' => $this->clientId,
            'clientIdHash' => '',
            'transactionType' => $this->transactionType->value,
            'transactionAmount' => [
                'paymentAmount' => $this->paymentAmountCents,
            ],
            'redirect' => [
                'returnUrl' => $this->returnUrl,
                'returnMethod' => 'GET',
            ],
            'useReliability' => true,
        ];

        if ($this->currency) {
            $data['transactionAmount']['currency'] = $this->currency;
        }

        if ($this->clientRef) {
            $data['clientRef'] = $this->clientRef;
        }

        if ($this->comment !== '') {
            $data['comment'] = $this->comment;
        }

        $data['tokenize'] = $this->tokenize;

        if ($this->tokenReference !== null) {
            $data['tokenReference'] = $this->tokenReference;
        }

        if (! empty($this->extraData)) {
            $data['extraData'] = $this->extraData;
        }

        return $data;
    }
}
