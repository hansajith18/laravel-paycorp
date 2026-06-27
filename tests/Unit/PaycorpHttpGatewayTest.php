<?php

namespace Hansajith18\LaravelPaycorp\Tests\Unit;

use Hansajith18\LaravelPaycorp\DTOs\PaymentCompleteRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentCompleteResponse;
use Hansajith18\LaravelPaycorp\DTOs\PaymentInitRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentInitResponse;
use Hansajith18\LaravelPaycorp\Enums\PaycorpTransactionType;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpConnectionException;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpInvalidResponseException;
use Hansajith18\LaravelPaycorp\Gateway\PaycorpHttpGateway;
use Hansajith18\LaravelPaycorp\Logging\PaycorpLogger;
use Hansajith18\LaravelPaycorp\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class PaycorpHttpGatewayTest extends TestCase
{
    private PaycorpHttpGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new PaycorpHttpGateway(config('paycorp'), $this->app->make(PaycorpLogger::class));
    }

    private function makeInitRequest(): PaymentInitRequest
    {
        return new PaymentInitRequest(
            clientId: 10000001,
            paymentAmountCents: 5000,
            currency: 'LKR',
            returnUrl: 'https://yourapp.test/api/payments/sampath/return',
            clientRef: 'PAY-TEST-001',
            transactionType: PaycorpTransactionType::PURCHASE,
        );
    }

    // ─── Payment Init: Success ─────────────────────────────────

    public function test_payment_init_returns_response_on_success(): void
    {
        Http::fake([
            '*' => Http::response([
                'msgId' => 'TEST-MSG-001',
                'responseData' => [
                    'reqid' => 'Z02W1pFzT5mxhR3SAyCw',
                    'expireAt' => '2026-06-10T12:00:00.000+0000',
                    'paymentPageUrl' => 'https://paycorp.example.com/webinterface/app/payment?reqid=Z02W1pFzT5mxhR3SAyCw',
                ],
            ], 200),
        ]);

        $response = $this->gateway->paymentInit($this->makeInitRequest());

        $this->assertInstanceOf(PaymentInitResponse::class, $response);
        $this->assertEquals('Z02W1pFzT5mxhR3SAyCw', $response->reqId);
        $this->assertStringContainsString('payment?reqid=', $response->paymentPageUrl);
        $this->assertNotEmpty($response->expireAt);
        $this->assertEquals('TEST-MSG-001', $response->msgId);
    }

    // ─── Payment Init: Timeout ─────────────────────────────────

    public function test_payment_init_throws_on_connection_timeout(): void
    {
        Http::fake([
            '*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->expectException(PaycorpConnectionException::class);
        $this->expectExceptionMessageMatches('/network error/i');

        $this->gateway->paymentInit($this->makeInitRequest());
    }

    // ─── Payment Init: Invalid JSON ────────────────────────────

    public function test_payment_init_throws_on_non_json_response(): void
    {
        Http::fake([
            '*' => Http::response('not json', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(PaycorpInvalidResponseException::class);

        $this->gateway->paymentInit($this->makeInitRequest());
    }

    // ─── Payment Init: Missing reqid ───────────────────────────

    public function test_payment_init_throws_when_reqid_missing(): void
    {
        Http::fake([
            '*' => Http::response([
                'msgId' => 'TEST-MSG-002',
                'responseData' => [
                    'paymentPageUrl' => 'https://example.com/pay',
                ],
            ], 200),
        ]);

        $this->expectException(PaycorpInvalidResponseException::class);
        $this->expectExceptionMessageMatches('/reqid/');

        $this->gateway->paymentInit($this->makeInitRequest());
    }

    // ─── Payment Init: HTTP 500 ────────────────────────────────

    public function test_payment_init_throws_on_http_error(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'server error'], 500),
        ]);

        $this->expectException(PaycorpInvalidResponseException::class);
        $this->expectExceptionMessageMatches('/500/');

        $this->gateway->paymentInit($this->makeInitRequest());
    }

    // ─── Payment Complete: Success ─────────────────────────────

    public function test_payment_complete_returns_approved_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'msgId' => 'TEST-MSG-003',
                'responseData' => [
                    'clientId' => 10000001,
                    'transactionType' => 'PURCHASE',
                    'creditCard' => [
                        'type' => 'VISA',
                        'holderName' => 'Test User',
                        'number' => '456445****4564',
                        'expiry' => '0926',
                    ],
                    'transactionAmount' => [
                        'totalAmount' => 0,
                        'paymentAmount' => 5000,
                        'serviceFeeAmount' => 0,
                        'currency' => 'LKR',
                    ],
                    'clientRef' => 'PAY-TEST-001',
                    'comment' => '',
                    'extraData' => [],
                    'txnReference' => '2026100009621577',
                    'responseCode' => '00',
                    'responseText' => 'TRANSACTION APPROVED',
                    'tokenized' => false,
                    'settlementDate' => '2026-06-10',
                    'authCode' => '826233',
                    'eci' => '05',
                    'threeDSServerTransID' => '59f81576-1ea2-48f6-ab86-b242345391a6',
                ],
            ], 200),
        ]);

        $request = new PaymentCompleteRequest(
            clientId: 10000001,
            reqId: 'Z02W1pFzT5mxhR3SAyCw',
        );

        $response = $this->gateway->paymentComplete($request);

        $this->assertInstanceOf(PaymentCompleteResponse::class, $response);
        $this->assertTrue($response->isApproved());
        $this->assertEquals('00', $response->responseCode);
        $this->assertEquals('TRANSACTION APPROVED', $response->responseText);
        $this->assertEquals('2026100009621577', $response->txnReference);
        $this->assertEquals('826233', $response->authCode);
        $this->assertEquals('VISA', $response->cardType);
        $this->assertEquals('456445****4564', $response->maskedCardNumber);
        $this->assertEquals('4564', $response->cardLast4());
        $this->assertEquals(5000, $response->paymentAmountCents);
        $this->assertEquals('LKR', $response->currency);
        $this->assertEquals('PAY-TEST-001', $response->clientRef);
    }

    // ─── Payment Complete: Failed Transaction ──────────────────

    public function test_payment_complete_returns_failed_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'msgId' => 'TEST-MSG-004',
                'responseData' => [
                    'clientId' => 10000001,
                    'transactionType' => 'PURCHASE',
                    'creditCard' => [
                        'type' => 'VISA',
                        'number' => '456445****4564',
                        'expiry' => '0926',
                    ],
                    'transactionAmount' => [
                        'paymentAmount' => 5000,
                        'currency' => 'LKR',
                    ],
                    'clientRef' => 'PAY-TEST-002',
                    'comment' => '',
                    'extraData' => [],
                    'txnReference' => '2026100009621578',
                    'responseCode' => '05',
                    'responseText' => 'DO NOT HONOUR',
                    'tokenized' => false,
                ],
            ], 200),
        ]);

        $request = new PaymentCompleteRequest(
            clientId: 10000001,
            reqId: 'FAILED-REQ-ID',
        );

        $response = $this->gateway->paymentComplete($request);

        $this->assertFalse($response->isApproved());
        $this->assertEquals('05', $response->responseCode);
        $this->assertEquals('DO NOT HONOUR', $response->responseText);
    }

    // ─── Payment Complete: Missing responseCode ────────────────

    public function test_payment_complete_throws_when_response_code_missing(): void
    {
        Http::fake([
            '*' => Http::response([
                'msgId' => 'TEST-MSG-005',
                'responseData' => [
                    'clientId' => 10000001,
                    'txnReference' => '123',
                ],
            ], 200),
        ]);

        $this->expectException(PaycorpInvalidResponseException::class);
        $this->expectExceptionMessageMatches('/responseCode/');

        $request = new PaymentCompleteRequest(clientId: 10000001, reqId: 'SOME-REQ');
        $this->gateway->paymentComplete($request);
    }

    // ─── Client ID Resolution ──────────────────────────────────

    public function test_get_client_id_returns_lkr_by_default(): void
    {
        $this->assertEquals(10000001, $this->gateway->getClientId());
    }

    public function test_get_client_id_returns_usd_for_usd_currency(): void
    {
        $this->assertEquals(10000002, $this->gateway->getClientId('USD'));
    }

    // ─── Safe Response Array ───────────────────────────────────

    public function test_safe_array_excludes_sensitive_fields(): void
    {
        Http::fake([
            '*' => Http::response([
                'msgId' => 'TEST-MSG-006',
                'responseData' => [
                    'clientId' => 10000001,
                    'transactionType' => 'PURCHASE',
                    'creditCard' => [
                        'type' => 'VISA',
                        'holderName' => 'Secret User',
                        'number' => '456445****4564',
                        'expiry' => '0926',
                    ],
                    'transactionAmount' => [
                        'paymentAmount' => 5000,
                        'currency' => 'LKR',
                    ],
                    'clientRef' => 'PAY-TEST-003',
                    'comment' => 'test comment',
                    'extraData' => [],
                    'txnReference' => '2026100009621579',
                    'responseCode' => '00',
                    'responseText' => 'TRANSACTION APPROVED',
                    'tokenized' => false,
                    'authCode' => '123456',
                ],
            ], 200),
        ]);

        $request = new PaymentCompleteRequest(clientId: 10000001, reqId: 'SAFE-REQ');
        $response = $this->gateway->paymentComplete($request);

        $safe = $response->toSafeArray();

        // Should include safe fields
        $this->assertArrayHasKey('txn_reference', $safe);
        $this->assertArrayHasKey('response_code', $safe);
        $this->assertArrayHasKey('masked_card_number', $safe);
        $this->assertArrayHasKey('card_last4', $safe);
        $this->assertArrayHasKey('card_type', $safe);

        // Should NOT include raw cardholder name or full expiry as separate sensitive fields
        $this->assertArrayNotHasKey('card_holder_name', $safe);
        $this->assertArrayNotHasKey('card_expiry', $safe);
        $this->assertArrayNotHasKey('comment', $safe);
    }

    // ─── DTO: PaymentInitRequest builds correct request data ───

    public function test_init_request_dto_builds_correct_payload(): void
    {
        $request = new PaymentInitRequest(
            clientId: 10000001,
            paymentAmountCents: 10000,
            currency: 'LKR',
            returnUrl: 'https://yourapp.test/return',
            clientRef: 'REF-123',
            transactionType: PaycorpTransactionType::PURCHASE,
            comment: 'Test payment',
            extraData: ['order_id' => '456'],
        );

        $data = $request->toRequestData();

        $this->assertSame(10000001, $data['clientId']);
        $this->assertSame('', $data['clientIdHash']);
        $this->assertEquals('PURCHASE', $data['transactionType']);
        $this->assertEquals(10000, $data['transactionAmount']['paymentAmount']);
        $this->assertEquals('LKR', $data['transactionAmount']['currency']);
        $this->assertEquals('https://yourapp.test/return', $data['redirect']['returnUrl']);
        $this->assertEquals('GET', $data['redirect']['returnMethod']);
        $this->assertEquals('REF-123', $data['clientRef']);
        $this->assertEquals('Test payment', $data['comment']);
        $this->assertFalse($data['tokenize']);
        $this->assertArrayNotHasKey('totalAmount', $data['transactionAmount']);
        $this->assertArrayNotHasKey('serviceFeeAmount', $data['transactionAmount']);
        $this->assertArrayNotHasKey('cancelUrl', $data['redirect']);
        $this->assertEquals(['order_id' => '456'], $data['extraData']);
    }

    // ─── DTO: PaymentCompleteResponse card last4 extraction ────

    public function test_card_last4_extracts_from_masked_number(): void
    {
        $response = new PaymentCompleteResponse(
            msgId: 'MSG-1',
            clientId: 10000001,
            transactionType: 'PURCHASE',
            responseCode: '00',
            responseText: 'APPROVED',
            txnReference: 'TXN-1',
            authCode: '111',
            settlementDate: '2026-06-10',
            cardType: 'MASTERCARD',
            maskedCardNumber: '512345****1234',
            cardExpiry: '1228',
            cardHolderName: 'Test',
            paymentAmountCents: 1000,
            currency: 'LKR',
            clientRef: 'REF-1',
            comment: '',
            tokenized: false,
            tokenReference: null,
            extraData: [],
            eci: null,
            threeDSServerTransId: null,
        );

        $this->assertEquals('1234', $response->cardLast4());
    }

    public function test_card_last4_returns_null_when_no_card(): void
    {
        $response = new PaymentCompleteResponse(
            msgId: 'MSG-2',
            clientId: 10000001,
            transactionType: 'PURCHASE',
            responseCode: '00',
            responseText: 'APPROVED',
            txnReference: 'TXN-2',
            authCode: null,
            settlementDate: null,
            cardType: null,
            maskedCardNumber: null,
            cardExpiry: null,
            cardHolderName: null,
            paymentAmountCents: 1000,
            currency: 'LKR',
            clientRef: 'REF-2',
            comment: '',
            tokenized: false,
            tokenReference: null,
            extraData: [],
            eci: null,
            threeDSServerTransId: null,
        );

        $this->assertNull($response->cardLast4());
    }

    public function test_build_payload_uses_version_1_04_date_format(): void
    {
        Http::fake([
            '*' => Http::response([
                'msgId' => 'TEST-MSG',
                'responseData' => [
                    'reqid' => 'Z02W1pFzT5mxhR3SAyCw',
                    'expireAt' => '2026-06-10T12:00:00.000+0000',
                    'paymentPageUrl' => 'https://example.com/pay',
                ],
            ], 200),
        ]);

        $gateway = new PaycorpHttpGateway(array_merge(config('paycorp'), [
            'api_version' => '1.04',
        ]), $this->app->make(PaycorpLogger::class));

        $gateway->paymentInit($this->makeInitRequest());

        Http::assertSent(function (Request $request) {
            $payload = json_decode($request->body(), true);
            $this->assertEquals('1.04', $payload['version']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $payload['requestDate']);

            return true;
        });
    }

    public function test_build_payload_uses_version_1_5_date_format(): void
    {
        Http::fake([
            '*' => Http::response([
                'msgId' => 'TEST-MSG',
                'responseData' => [
                    'reqid' => 'Z02W1pFzT5mxhR3SAyCw',
                    'expireAt' => '2026-06-10T12:00:00.000+0000',
                    'paymentPageUrl' => 'https://example.com/pay',
                ],
            ], 200),
        ]);

        $gateway = new PaycorpHttpGateway(array_merge(config('paycorp'), [
            'api_version' => '1.5',
        ]), $this->app->make(PaycorpLogger::class));

        $gateway->paymentInit($this->makeInitRequest());

        Http::assertSent(function (Request $request) {
            $payload = json_decode($request->body(), true);
            $this->assertEquals('1.5', $payload['version']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{4}$/', $payload['requestDate']);

            return true;
        });
    }
}
