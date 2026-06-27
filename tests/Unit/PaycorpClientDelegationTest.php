<?php

namespace Hansajith18\LaravelPaycorp\Tests\Unit;

use Hansajith18\LaravelPaycorp\Contracts\PaycorpGatewayInterface;
use Hansajith18\LaravelPaycorp\Contracts\PaycorpRefundGatewayInterface;
use Hansajith18\LaravelPaycorp\DTOs\PaymentRealTimeRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentRealTimeResponse;
use Hansajith18\LaravelPaycorp\DTOs\RefundRequest;
use Hansajith18\LaravelPaycorp\DTOs\RefundResponse;
use Hansajith18\LaravelPaycorp\PaycorpClient;
use Hansajith18\LaravelPaycorp\Tests\TestCase;
use Illuminate\Validation\ValidationException;
use Mockery;

class PaycorpClientDelegationTest extends TestCase
{
    public function test_real_time_payment_uses_tokenized_client_id(): void
    {
        $expected = PaymentRealTimeResponse::fromApiResponse([]);

        $gateway = Mockery::mock(PaycorpGatewayInterface::class);
        $gateway->shouldReceive('getClientId')
            ->with('LKR', true) // tokenized client ID must be requested
            ->once()
            ->andReturn(20000001);
        $gateway->shouldReceive('paymentRealTime')
            ->once()
            ->with(Mockery::on(fn ($req) => $req instanceof PaymentRealTimeRequest && $req->clientId === 20000001))
            ->andReturn($expected);

        $client = new PaycorpClient($gateway);

        $response = $client->realTimePayment(
            cardToken: 'TOKEN-123',
            cardExpiry: '1228',
            amountCents: 5000,
            clientRef: 'PAY-RT-1',
            currency: 'LKR',
        );

        $this->assertSame($expected, $response);
    }

    public function test_is_refund_configured_reflects_presence_of_refund_gateway(): void
    {
        $gateway = Mockery::mock(PaycorpGatewayInterface::class);

        $withoutRefund = new PaycorpClient($gateway);
        $this->assertFalse($withoutRefund->isRefundConfigured());

        $refundGateway = Mockery::mock(PaycorpRefundGatewayInterface::class);
        $withRefund = new PaycorpClient($gateway, $refundGateway);
        $this->assertTrue($withRefund->isRefundConfigured());
    }

    public function test_refund_throws_validation_exception_when_refund_gateway_missing(): void
    {
        $gateway = Mockery::mock(PaycorpGatewayInterface::class);
        $client = new PaycorpClient($gateway);

        $this->expectException(ValidationException::class);

        $client->refund(
            originalTxnReference: 'TXN-1',
            amountCents: 5000,
            clientRef: 'REF-1',
            currency: 'LKR',
        );
    }

    public function test_void_delegates_to_refund_gateway(): void
    {
        $expected = RefundResponse::fromApiResponse([]);

        $gateway = Mockery::mock(PaycorpGatewayInterface::class);

        $refundGateway = Mockery::mock(PaycorpRefundGatewayInterface::class);
        $refundGateway->shouldReceive('getClientId')
            ->with('LKR')
            ->once()
            ->andReturn(30000001);
        $refundGateway->shouldReceive('refund')
            ->once()
            ->with(Mockery::on(fn ($req) => $req instanceof RefundRequest && $req->clientId === 30000001))
            ->andReturn($expected);

        $client = new PaycorpClient($gateway, $refundGateway);

        $response = $client->void(
            originalTxnReference: 'TXN-9',
            amountCents: 5000,
            clientRef: 'PAY-1-VOID',
            currency: 'LKR',
        );

        $this->assertSame($expected, $response);
    }

    public function test_get_last_request_payload_proxies_to_gateway(): void
    {
        $gateway = Mockery::mock(PaycorpGatewayInterface::class);
        $gateway->shouldReceive('getLastRequestPayload')
            ->once()
            ->andReturn(['operation' => 'PAYMENT_INIT']);

        $client = new PaycorpClient($gateway);

        $this->assertSame(['operation' => 'PAYMENT_INIT'], $client->getLastRequestPayload());
    }

    public function test_get_last_refund_request_payload_returns_empty_when_no_refund_gateway(): void
    {
        $gateway = Mockery::mock(PaycorpGatewayInterface::class);
        $client = new PaycorpClient($gateway);

        $this->assertSame([], $client->getLastRefundRequestPayload());
    }
}
