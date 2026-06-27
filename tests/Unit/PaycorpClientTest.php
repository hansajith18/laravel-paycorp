<?php

namespace Hansajith18\LaravelPaycorp\Tests\Unit;

use Hansajith18\LaravelPaycorp\Contracts\PaycorpGatewayInterface;
use Hansajith18\LaravelPaycorp\DTOs\PaymentCompleteResponse;
use Hansajith18\LaravelPaycorp\DTOs\PaymentInitResponse;
use Hansajith18\LaravelPaycorp\PaycorpClient;
use Hansajith18\LaravelPaycorp\Tests\TestCase;
use Mockery;

class PaycorpClientTest extends TestCase
{
    public function test_init_payment_delegates_to_gateway(): void
    {
        $expectedResponse = new PaymentInitResponse(
            reqId: 'REQ-123',
            paymentPageUrl: 'https://paycorp.test/pay?reqid=REQ-123',
            expireAt: '2026-06-10T12:00:00Z',
            msgId: 'MSG-1',
        );

        $gateway = Mockery::mock(PaycorpGatewayInterface::class);
        $gateway->shouldReceive('getClientId')
            ->with('LKR')
            ->once()
            ->andReturn(10000001);
        $gateway->shouldReceive('paymentInit')
            ->once()
            ->andReturn($expectedResponse);

        $client = new PaycorpClient($gateway);

        $response = $client->initPayment(
            amountCents: 5000,
            clientRef: 'PAY-001',
            returnUrl: 'https://yourapp.test/return',
            currency: 'LKR',
        );

        $this->assertEquals('REQ-123', $response->reqId);
        $this->assertStringContainsString('paycorp.test', $response->paymentPageUrl);
    }

    public function test_complete_payment_delegates_to_gateway(): void
    {
        $expectedResponse = new PaymentCompleteResponse(
            msgId: 'MSG-2',
            clientId: 10000001,
            transactionType: 'PURCHASE',
            responseCode: '00',
            responseText: 'TRANSACTION APPROVED',
            txnReference: 'TXN-456',
            authCode: '999111',
            settlementDate: '2026-06-10',
            cardType: 'VISA',
            maskedCardNumber: '456445****4564',
            cardExpiry: '0928',
            cardHolderName: 'Test',
            paymentAmountCents: 5000,
            currency: 'LKR',
            clientRef: 'PAY-001',
            comment: '',
            tokenized: false,
            tokenReference: null,
            extraData: [],
            eci: '05',
            threeDSServerTransId: null,
        );

        $gateway = Mockery::mock(PaycorpGatewayInterface::class);
        $gateway->shouldReceive('getClientId')
            ->with('LKR')
            ->once()
            ->andReturn(10000001);
        $gateway->shouldReceive('paymentComplete')
            ->once()
            ->andReturn($expectedResponse);

        $client = new PaycorpClient($gateway);

        $response = $client->completePayment('REQ-123', 'LKR');

        $this->assertTrue($response->isApproved());
        $this->assertEquals('TXN-456', $response->txnReference);
    }
}
