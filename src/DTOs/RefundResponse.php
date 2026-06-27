<?php

namespace Hansajith18\LaravelPaycorp\DTOs;

use Hansajith18\LaravelPaycorp\Enums\PaycorpResponseCode;

final readonly class RefundResponse
{
    public function __construct(
        public string $msgId,
        public int $clientId,
        public string $transactionType,
        public string $responseCode,
        public string $responseText,
        public ?string $txnReference,
        public ?string $originalTxnReference,
        public ?string $authCode,
        public ?string $settlementDate,
        public int $paymentAmountCents,
        public string $currency,
        public string $clientRef,
        public string $comment,
        public ?array $rawResponse = null,
    ) {}

    public static function fromApiResponse(array $data): self
    {
        $rd = $data['responseData'] ?? [];
        $amount = $rd['transactionAmount'] ?? [];

        return new self(
            msgId: $data['msgId'] ?? '',
            clientId: (int) ($rd['clientId'] ?? 0),
            transactionType: $rd['transactionType'] ?? '',
            responseCode: $rd['responseCode'] ?? '',
            responseText: $rd['responseText'] ?? '',
            txnReference: $rd['txnReference'] ?? null,
            originalTxnReference: $rd['originalTxnReference'] ?? null,
            authCode: $rd['authCode'] ?? null,
            settlementDate: $rd['settlementDate'] ?? null,
            paymentAmountCents: (int) ($amount['paymentAmount'] ?? 0),
            currency: $amount['currency'] ?? '',
            clientRef: $rd['clientRef'] ?? '',
            comment: $rd['comment'] ?? '',
            rawResponse: $data,
        );
    }

    public function getResponseCodeEnum(): ?PaycorpResponseCode
    {
        return PaycorpResponseCode::tryFrom($this->responseCode);
    }

    public function isApproved(): bool
    {
        return $this->responseCode === PaycorpResponseCode::APPROVED->value;
    }

    public function toSafeArray(): array
    {
        return [
            'txn_reference' => $this->txnReference,
            'original_txn_reference' => $this->originalTxnReference,
            'response_code' => $this->responseCode,
            'response_text' => $this->responseText,
            'auth_code' => $this->authCode,
            'settlement_date' => $this->settlementDate,
            'payment_amount_cents' => $this->paymentAmountCents,
            'currency' => $this->currency,
            'client_ref' => $this->clientRef,
            'transaction_type' => $this->transactionType,
        ];
    }
}
