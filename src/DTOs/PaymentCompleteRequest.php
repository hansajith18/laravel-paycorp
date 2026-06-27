<?php

namespace Hansajith18\LaravelPaycorp\DTOs;

final readonly class PaymentCompleteRequest
{
    public function __construct(
        public int $clientId,
        public string $reqId,
    ) {}

    public function toRequestData(): array
    {
        return [
            'clientId' => $this->clientId,
            'reqid' => $this->reqId,
        ];
    }
}
