<?php

namespace Hansajith18\LaravelPaycorp\Tests\Unit;

use Hansajith18\LaravelPaycorp\DTOs\PaymentCompleteResponse;
use Hansajith18\LaravelPaycorp\Tests\TestCase;

class SensitiveDataTest extends TestCase
{
    private function makeResponse(array $overrides = []): PaymentCompleteResponse
    {
        return new PaymentCompleteResponse(
            msgId: $overrides['msgId'] ?? 'MSG-1',
            clientId: $overrides['clientId'] ?? 10000001,
            transactionType: $overrides['transactionType'] ?? 'PURCHASE',
            responseCode: $overrides['responseCode'] ?? '00',
            responseText: $overrides['responseText'] ?? 'APPROVED',
            txnReference: $overrides['txnReference'] ?? 'TXN-1',
            authCode: $overrides['authCode'] ?? '123456',
            settlementDate: $overrides['settlementDate'] ?? '2026-06-10',
            cardType: $overrides['cardType'] ?? 'VISA',
            maskedCardNumber: $overrides['maskedCardNumber'] ?? '456445****4564',
            cardExpiry: $overrides['cardExpiry'] ?? '0926',
            cardHolderName: $overrides['cardHolderName'] ?? 'Secret Name',
            paymentAmountCents: $overrides['paymentAmountCents'] ?? 5000,
            currency: $overrides['currency'] ?? 'LKR',
            clientRef: $overrides['clientRef'] ?? 'REF-1',
            comment: $overrides['comment'] ?? 'internal comment',
            tokenized: $overrides['tokenized'] ?? false,
            tokenReference: $overrides['tokenReference'] ?? null,
            extraData: $overrides['extraData'] ?? [],
            eci: $overrides['eci'] ?? null,
            threeDSServerTransId: $overrides['threeDSServerTransId'] ?? null,
        );
    }

    public function test_safe_array_does_not_contain_cardholder_name(): void
    {
        $response = $this->makeResponse(['cardHolderName' => 'John Secret Doe']);
        $safe = $response->toSafeArray();

        $serialized = json_encode($safe);
        $this->assertStringNotContainsString('John Secret Doe', $serialized);
    }

    public function test_safe_array_does_not_contain_full_card_expiry(): void
    {
        $response = $this->makeResponse();
        $safe = $response->toSafeArray();

        $this->assertArrayNotHasKey('card_expiry', $safe);
    }

    public function test_safe_array_does_not_contain_raw_comment(): void
    {
        $response = $this->makeResponse(['comment' => 'sensitive internal data']);
        $safe = $response->toSafeArray();

        $serialized = json_encode($safe);
        $this->assertStringNotContainsString('sensitive internal data', $serialized);
    }

    public function test_safe_array_preserves_masked_card_number(): void
    {
        $response = $this->makeResponse(['maskedCardNumber' => '456445****4564']);
        $safe = $response->toSafeArray();

        $this->assertEquals('456445****4564', $safe['masked_card_number']);
        $this->assertEquals('4564', $safe['card_last4']);
    }

    public function test_safe_array_includes_only_safe_keys(): void
    {
        $response = $this->makeResponse();
        $safe = $response->toSafeArray();

        $allowedKeys = [
            'txn_reference', 'response_code', 'response_text', 'auth_code',
            'settlement_date', 'card_type', 'masked_card_number', 'card_last4',
            'payment_amount_cents', 'currency', 'client_ref', 'tokenized', 'token', 'token_reference', 'eci',
        ];

        foreach (array_keys($safe) as $key) {
            $this->assertContains($key, $allowedKeys, "Unexpected key '{$key}' in safe array");
        }
    }

    public function test_response_code_enum_resolves_correctly(): void
    {
        $response = $this->makeResponse(['responseCode' => '00']);
        $enum = $response->getResponseCodeEnum();

        $this->assertNotNull($enum);
        $this->assertTrue($enum->isApproved());
    }

    public function test_unknown_response_code_returns_null_enum(): void
    {
        $response = $this->makeResponse(['responseCode' => '99']);
        $this->assertNull($response->getResponseCodeEnum());
    }
}
