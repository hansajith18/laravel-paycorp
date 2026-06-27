<?php

namespace Hansajith18\LaravelPaycorp\Gateway;

use Hansajith18\LaravelPaycorp\Contracts\PaycorpGatewayInterface;
use Hansajith18\LaravelPaycorp\DTOs\PaymentCompleteRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentCompleteResponse;
use Hansajith18\LaravelPaycorp\DTOs\PaymentInitRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentInitResponse;
use Hansajith18\LaravelPaycorp\DTOs\PaymentRealTimeRequest;
use Hansajith18\LaravelPaycorp\DTOs\PaymentRealTimeResponse;
use Hansajith18\LaravelPaycorp\Enums\PaycorpOperation;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpConnectionException;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpInvalidResponseException;
use Hansajith18\LaravelPaycorp\Logging\PaycorpLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * HTTP gateway for the Paycorp / Sampath Paycenter API.
 *
 * The class is NOT declared `readonly` so that `$lastRequestPayload` can be
 * mutated on every outgoing call (enabling service-layer request logging).
 */
class PaycorpHttpGateway implements PaycorpGatewayInterface
{
    /**
     * The full JSON-ready payload sent in the most recent API call.
     * Available immediately after any gateway method returns (or throws).
     */
    private array $lastRequestPayload = [];

    public function __construct(
        private readonly array $config,
        private readonly PaycorpLogger $logger,
    ) {}

    /**
     * @throws JsonException
     */
    public function paymentInit(PaymentInitRequest $request): PaymentInitResponse
    {
        $operation = PaycorpOperation::PAYMENT_INIT;
        $msgId = $this->generateMsgId();
        $payload = $this->buildPayload($operation, $msgId, $request->toRequestData());

        $responseData = $this->sendRequest($operation, $payload);

        try {
            $this->validateInitResponse($responseData);
        } catch (PaycorpInvalidResponseException $e) {
            throw $e->setRawResponse($responseData);
        }

        return PaymentInitResponse::fromApiResponse($responseData);
    }

    /**
     * @throws JsonException
     */
    public function paymentComplete(PaymentCompleteRequest $request): PaymentCompleteResponse
    {
        $operation = PaycorpOperation::PAYMENT_COMPLETE;
        $msgId = $this->generateMsgId();
        $payload = $this->buildPayload($operation, $msgId, $request->toRequestData());

        $responseData = $this->sendRequest($operation, $payload);

        try {
            $this->validateCompleteResponse($responseData);
        } catch (PaycorpInvalidResponseException $e) {
            throw $e->setRawResponse($responseData);
        }

        return PaymentCompleteResponse::fromApiResponse($responseData);
    }

    /**
     * @throws JsonException
     */
    public function paymentRealTime(PaymentRealTimeRequest $request): PaymentRealTimeResponse
    {
        $operation = PaycorpOperation::PAYMENT_REAL_TIME;
        $msgId = $this->generateMsgId();

        $payload = $this->buildPayload($operation, $msgId, $request->toRequestData());

        $responseData = $this->sendRequest($operation, $payload);

        try {
            $this->validateRealTimeResponse($responseData);
        } catch (PaycorpInvalidResponseException $e) {
            throw $e->setRawResponse($responseData);
        }

        return PaymentRealTimeResponse::fromApiResponse($responseData);
    }

    /**
     * Returns the full Paycorp request envelope sent during the most recent
     * gateway call (set before the HTTP round-trip, so it is available even
     * when the call throws a PaycorpConnectionException).
     */
    public function getLastRequestPayload(): array
    {
        return $this->lastRequestPayload;
    }

    public function getClientId(?string $currency = null, bool $isTokenized = false): int
    {
        $currency = strtoupper($currency ?? $this->config['default_currency'] ?? 'LKR');

        $configKey = match (true) {
            $currency === 'USD' && $isTokenized => 'tokenize_payment_client_id_usd',
            $currency === 'USD' => 'client_id_usd',
            $isTokenized => 'tokenize_payment_client_id_lkr',
            default => 'client_id_lkr',
        };

        $clientId = $this->config[$configKey] ?? null;

        if (empty($clientId)) {
            $envName = strtoupper($configKey === 'tokenize_payment_client_id_lkr' ? 'PAYCORP_TOKEN_CLIENT_ID_LKR'
                : ($configKey === 'tokenize_payment_client_id_usd' ? 'PAYCORP_TOKEN_CLIENT_ID_USD'
                : 'PAYCORP_' . strtoupper($configKey)));

            throw ValidationException::withMessages([
                $configKey => ["Paycorp config [{$configKey}] is required for {$currency}" .
                            ($isTokenized ? ' tokenised' : '') .
                            " payments. Set the {$envName} environment variable."],
            ]);
        }

        return (int) $clientId;
    }

    private function buildPayload(PaycorpOperation $operation, string $msgId, array $requestData): array
    {
        $version = $this->config['api_version'] ?? '1.5';
        $requestDate = ($version === '1.04')
            ? now()->format('Y-m-d H:i:s')
            : now()->format('Y-m-d\TH:i:s.vO');

        return [
            'version' => $version,
            'msgId' => $msgId,
            'operation' => $operation->value,
            'requestDate' => $requestDate,
            'validateOnly' => false,
            ...($operation === PaycorpOperation::PAYMENT_REAL_TIME
                ? [
                    'serviceId' => 'lk_smp_ppe_engine',
                    'resource' => 'PAYMENT',
                    'action' => 'REALTIME',
                ]
                : []),
            'requestData' => $requestData,
        ];
    }

    /**
     * @throws JsonException
     */
    private function sendRequest(PaycorpOperation $operation, array $payload): array
    {
        // Store the full outgoing envelope BEFORE the HTTP call so it is
        // always available to the service layer for logging, even on failure.
        $this->lastRequestPayload = $payload;

        $jsonBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $hmac = $this->generateHmac($jsonBody);

        try {
            $response = Http::withHeaders([
                'AUTHTOKEN' => $this->config['auth_token'],
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache',
                'HMAC' => $hmac,
            ])
                ->timeout($this->config['timeout'] ?? 30)
                ->connectTimeout($this->config['connect_timeout'] ?? 10)
                ->retry(
                    $this->config['retry_times'] ?? 2,
                    $this->config['retry_sleep_ms'] ?? 500,
                    fn ($exception) => $exception instanceof ConnectionException,
                )
                ->withOptions([
                    'verify' => true,
                ])
                ->withBody($jsonBody)
                ->post($this->config['endpoint']);

        } catch (RequestException $e) {
            $this->logger->error('Paycorp returned non-200 status via exception', [
                'operation' => $operation->value,
                'status' => $e->response->status(),
            ]);

            throw PaycorpInvalidResponseException::unexpectedStatus(
                $operation->value,
                $e->response->status()
            );
        } catch (ConnectionException $e) {
            $this->logger->error('Paycorp connection failed', [
                'operation' => $operation->value,
                'error' => $e->getMessage(),
            ]);

            throw PaycorpConnectionException::networkError(
                $operation->value,
                $e->getMessage()
            );
        }

        if (! $response->successful()) {
            $this->logger->error('Paycorp returned non-200 status', [
                'operation' => $operation->value,
                'status' => $response->status(),
            ]);

            throw PaycorpInvalidResponseException::unexpectedStatus(
                $operation->value,
                $response->status()
            )->setRawResponse($response->json() ?: ['body' => $response->body()]);
        }

        $data = $response->json();

        if (! is_array($data)) {
            $this->logger->error('Paycorp returned invalid JSON', [
                'operation' => $operation->value,
            ]);

            throw PaycorpInvalidResponseException::malformedJson($operation->value)
                ->setRawResponse(['body' => $response->body()]);
        }

        return $data;
    }

    private function generateHmac(string $jsonString): string
    {
        return hash_hmac('sha256', mb_convert_encoding($jsonString, 'ISO-8859-1', 'UTF-8'), mb_convert_encoding($this->config['hmac_secret'], 'ISO-8859-1', 'UTF-8'));
    }

    private function generateMsgId(): string
    {
        return strtoupper(Str::uuid()->toString());
    }

    private function validateInitResponse(array $data): void
    {
        $responseData = $data['responseData'] ?? null;

        if (! is_array($responseData)) {
            throw PaycorpInvalidResponseException::missingField(
                PaycorpOperation::PAYMENT_INIT->value,
                'responseData'
            );
        }

        if (empty($responseData['reqid'])) {
            throw PaycorpInvalidResponseException::missingField(
                PaycorpOperation::PAYMENT_INIT->value,
                'reqid'
            );
        }

        if (empty($responseData['paymentPageUrl'])) {
            throw PaycorpInvalidResponseException::missingField(
                PaycorpOperation::PAYMENT_INIT->value,
                'paymentPageUrl'
            );
        }
    }

    private function validateCompleteResponse(array $data): void
    {
        $responseData = $data['responseData'] ?? null;

        if (! is_array($responseData)) {
            throw PaycorpInvalidResponseException::missingField(
                PaycorpOperation::PAYMENT_COMPLETE->value,
                'responseData'
            );
        }

        if (! isset($responseData['responseCode'])) {
            throw PaycorpInvalidResponseException::missingField(
                PaycorpOperation::PAYMENT_COMPLETE->value,
                'responseCode'
            );
        }
    }

    private function validateRealTimeResponse(array $data): void
    {
        $responseData = $data['responseData'] ?? null;

        if (! is_array($responseData)) {
            throw PaycorpInvalidResponseException::missingField(
                PaycorpOperation::PAYMENT_REAL_TIME->value,
                'responseData'
            );
        }

        if (! isset($responseData['responseCode'])) {
            throw PaycorpInvalidResponseException::missingField(
                PaycorpOperation::PAYMENT_REAL_TIME->value,
                'responseCode'
            );
        }
    }
}
