<?php

namespace Hansajith18\LaravelPaycorp\DTOs;

use Hansajith18\LaravelPaycorp\Enums\PaycorpResponseCode;

final readonly class PaymentCompleteResponse
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
        public bool $tokenized,
        public ?string $token = null,
        public ?string $tokenReference = null,
        public array $extraData = [],
        public ?string $eci = null,
        public ?string $threeDSServerTransId = null,
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
            tokenized: (bool) ($rd['tokenized'] ?? false),
            token: $rd['token'] ?? null,
            tokenReference: $rd['tokenReference'] ?? null,
            extraData: is_array($rd['extraData'] ?? null) ? $rd['extraData'] : [],
            eci: $rd['eci'] ?? null,
            threeDSServerTransId: $rd['threeDSServerTransID'] ?? null,
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
            'tokenized' => $this->tokenized,
            'token' => $this->token,
            'token_reference' => $this->tokenReference,
            'eci' => $this->eci,
        ];
    }
}
