<?php

namespace Hansajith18\LaravelPaycorp\DTOs;

final readonly class PaymentInitResponse
{
    public function __construct(
        public string $reqId,
        public string $paymentPageUrl,
        public string $expireAt,
        public string $msgId,
        public ?array $rawResponse = null,
    ) {}

    public static function fromApiResponse(array $data): self
    {
        $responseData = $data['responseData'] ?? [];

        return new self(
            reqId: $responseData['reqid'] ?? '',
            paymentPageUrl: $responseData['paymentPageUrl'] ?? '',
            expireAt: $responseData['expireAt'] ?? '',
            msgId: $data['msgId'] ?? '',
            rawResponse: $data,
        );
    }
}
