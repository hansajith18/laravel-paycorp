<?php

namespace Hansajith18\LaravelPaycorp\DTOs;

use Hansajith18\LaravelPaycorp\Enums\PaycorpResponseCode;

/**
 * Response DTO for PAYMENT_REAL_TIME operation.
 *
 * Maps the synchronous response returned by Paycorp after a real-time tokenized
 * card charge. Unlike hosted-page flows there is no redirect — the result is
 * available immediately in this response object.
 *
 * Reference: Paycenter Technical Guide — PAYMENT_REAL_TIME operation.
 */
final readonly class PaymentRealTimeResponse
{
    public function __construct(
        public string $msgId,
        public int $clientId,
        public string $transactionType,
        public string $responseCode,
        public string $responseText,
        public ?string $txnReference,
        public ?string $authCode,
        public ?string $settlementDate,
        public ?string $cardType,
        public ?string $maskedCardNumber,
        public ?string $cardExpiry,
        public ?string $cardHolderName,
        public int $paymentAmountCents,
        public string $currency,
        public string $clientRef,
        public string $comment,
        public array $extraData = [],
        public ?array $rawResponse = null,
    ) {}

    public static function fromApiResponse(array $data): self
    {
        $rd = $data['responseData'] ?? [];
        $card = $rd['creditCard'] ?? [];
        $amount = $rd['transactionAmount'] ?? [];

        return new self(
            msgId: $data['msgId'] ?? '',
            clientId: (int) ($rd['clientId'] ?? 0),
            transactionType: $rd['transactionType'] ?? '',
            responseCode: $rd['responseCode'] ?? '',
            responseText: $rd['responseText'] ?? '',
            txnReference: $rd['txnReference'] ?? null,
            authCode: $rd['authCode'] ?? null,
            settlementDate: $rd['settlementDate'] ?? null,
            cardType: $card['type'] ?? null,
            maskedCardNumber: $card['number'] ?? null,
            cardExpiry: $card['expiry'] ?? null,
            cardHolderName: $card['holderName'] ?? null,
            paymentAmountCents: (int) ($amount['paymentAmount'] ?? 0),
            currency: $amount['currency'] ?? '',
            clientRef: $rd['clientRef'] ?? '',
            comment: $rd['comment'] ?? '',
            extraData: is_array($rd['extraData'] ?? null) ? $rd['extraData'] : [],
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

    public function cardLast4(): ?string
    {
        if (! $this->maskedCardNumber) {
            return null;
        }

        return substr(preg_replace('/[^0-9]/', '', $this->maskedCardNumber), -4) ?: null;
    }

    public function toSafeArray(): array
    {
        return [
            'txn_reference' => $this->txnReference,
            'response_code' => $this->responseCode,
            'response_text' => $this->responseText,
            'auth_code' => $this->authCode,
            'settlement_date' => $this->settlementDate,
            'card_type' => $this->cardType,
            'masked_card_number' => $this->maskedCardNumber,
            'card_last4' => $this->cardLast4(),
            'payment_amount_cents' => $this->paymentAmountCents,
            'currency' => $this->currency,
            'client_ref' => $this->clientRef,
        ];
    }
}
