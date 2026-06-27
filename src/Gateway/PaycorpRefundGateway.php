<?php

namespace Hansajith18\LaravelPaycorp\Gateway;

use Hansajith18\LaravelPaycorp\Contracts\PaycorpRefundGatewayInterface;
use Hansajith18\LaravelPaycorp\DTOs\RefundRequest;
use Hansajith18\LaravelPaycorp\DTOs\RefundResponse;
use Hansajith18\LaravelPaycorp\Enums\PaycorpOperation;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpConnectionException;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpInvalidResponseException;
use Hansajith18\LaravelPaycorp\Exceptions\PaycorpRefundException;
use Hansajith18\LaravelPaycorp\Logging\PaycorpLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaycorpRefundGateway implements PaycorpRefundGatewayInterface
{
    private array $lastRequestPayload = [];

    private ?string $sessionToken = null;

    /** Assigned once in the constructor from config; must not change after that. */
    private readonly string $deviceId;

    public function __construct(
        private readonly array $config,
        private readonly PaycorpLogger $logger,
    ) {
        $this->validateRefundConfig($config);

        $this->deviceId = $config['refund_device_id'];
    }

    private function validateRefundConfig(array $config): void
    {
        $required = [
            'refund_device_id' => 'PAYCORP_REFUND_DEVICE_ID',
            'refund_iv_phrase' => 'PAYCORP_REFUND_IV_PHRASE',
            'refund_aes_secret' => 'PAYCORP_REFUND_AES_SECRET',
        ];

        foreach ($required as $key => $envName) {
            if (empty($config[$key])) {
                throw ValidationException::withMessages([
                    $key => ["Paycorp refund config [{$key}] is required for refund/void operations. " .
                    "Set the {$envName} environment variable."],
                ]);
            }
        }
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $operation = PaycorpOperation::PAYMENT_REAL_TIME;
        $payload = $this->buildPayload($request, $operation);
        $this->lastRequestPayload = $payload;

        $sessionToken = $this->getSessionToken();

        $responseData = $this->sendAuthenticatedRequest($payload, $sessionToken, $operation);

        $this->validateRefundResponse($responseData, $operation);

        return RefundResponse::fromApiResponse($responseData);
    }

    public function getLastRequestPayload(): array
    {
        return $this->lastRequestPayload;
    }

    public function getClientId(?string $currency = null): int
    {
        $currency = strtoupper($currency ?? $this->config['default_currency'] ?? 'LKR');

        return match ($currency) {
            'USD' => (int) $this->config['client_id_usd'],
            default => (int) $this->config['client_id_lkr'],
        };
    }

    private function getSessionToken(): string
    {
        if ($this->sessionToken !== null) {
            return $this->sessionToken;
        }

        $this->sessionToken = $this->login();

        return $this->sessionToken;
    }

    private function login(): string
    {
        $ivPhrase = $this->config['refund_iv_phrase'];
        $aesSecret = $this->config['refund_aes_secret'];
        $username = $this->config['refund_username'];
        $password = $this->config['refund_password'];

        if (empty($username)) {
            throw PaycorpRefundException::loginFailed(
                'Refund username is not configured. Set the PAYCORP_REFUND_USERNAME environment variable.'
            );
        }

        if (empty($password)) {
            throw PaycorpRefundException::loginFailed(
                'Refund password is not configured. Set the PAYCORP_REFUND_PASSWORD environment variable.'
            );
        }

        $encUsername = $this->encryptAes($username, $aesSecret, $ivPhrase);
        $encPassword = $this->encryptAes($password, $aesSecret, $ivPhrase);

        $endpoint = $this->getBaseUrl() . '/rest/login';

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Deviceid' => $this->deviceId,
            ])
                ->timeout($this->config['timeout'] ?? 30)
                ->connectTimeout($this->config['connect_timeout'] ?? 10)
                ->post($endpoint, [
                    'username' => $encUsername,
                    'password' => $encPassword,
                    'ivPhrase' => $ivPhrase,
                ]);
        } catch (ConnectionException $e) {
            throw PaycorpRefundException::loginFailed('Connection error: ' . $e->getMessage());
        }

        if (! $response->successful()) {
            $rawResponse = $response->json() ?: ['body' => $response->body()];
            throw PaycorpRefundException::loginFailed(
                "HTTP {$response->status()}: " . ($response->body() ?: 'No response body')
            )->setRawResponse($rawResponse);
        }

        $data = $response->json();
        $sessionToken = $data['sessionToken'] ?? null;

        if (empty($sessionToken)) {
            throw PaycorpRefundException::loginFailed(
                'No sessionToken in login response: ' . json_encode($data)
            )->setRawResponse($data);
        }

        return $sessionToken;
    }

    private function buildPayload(RefundRequest $request, PaycorpOperation $operation): array
    {
        $version = $this->config['api_version'] ?? '1.5';
        $requestDate = now()->format('Y-m-d\TH:i:s.vO');

        return [
            'transactionType' => $request->transactionType->value,
            'version' => $version,
            'msgId' => strtolower(Str::uuid()->toString()),
            'operation' => $operation->value,
            'requestDate' => $requestDate,
            'validateOnly' => false,
            'requestData' => $request->toRequestData(),
        ];
    }

    private function sendAuthenticatedRequest(array $payload, string $sessionToken, PaycorpOperation $operation): array
    {
        $this->lastRequestPayload = $payload;

        $endpoint = $this->getBaseUrl() . '/rest/service/proxy';
        $jsonBody = json_encode($payload);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => '*',
                'xauthtoken' => $sessionToken,
                'requestsource' => 'CONSOLE',
                'Deviceid' => $this->deviceId,
                'ivphrase' => $this->config['refund_iv_phrase'] ?? '',
            ])
                ->timeout($this->config['timeout'] ?? 30)
                ->connectTimeout($this->config['connect_timeout'] ?? 10)
                ->retry(
                    $this->config['retry_times'] ?? 2,
                    $this->config['retry_sleep_ms'] ?? 500,
                    fn ($exception) => $exception instanceof ConnectionException,
                )
                ->withBody($jsonBody)
                ->post($endpoint);
        } catch (RequestException $e) {
            $this->logger->error('Paycorp refund returned non-200 status via exception', [
                'operation' => $operation->value,
                'status' => $e->response->status(),
            ]);

            throw PaycorpInvalidResponseException::unexpectedStatus(
                $operation->value,
                $e->response->status()
            );
        } catch (ConnectionException $e) {
            $this->logger->error('Paycorp refund connection failed', [
                'operation' => $operation->value,
                'error' => $e->getMessage(),
            ]);

            throw PaycorpConnectionException::networkError(
                $operation->value,
                $e->getMessage()
            );
        }

        if (! $response->successful()) {
            $this->logger->error('Paycorp refund returned non-200 status', [
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
            throw PaycorpInvalidResponseException::malformedJson(
                $operation->value
            )->setRawResponse(['body' => $response->body()]);
        }

        return $data;
    }

    private function validateRefundResponse(array $data, PaycorpOperation $operation): void
    {
        $responseData = $data['responseData'] ?? null;

        if (! is_array($responseData)) {
            throw PaycorpInvalidResponseException::missingField(
                $operation->value,
                'responseData'
            );
        }

        if (! isset($responseData['responseCode'])) {
            throw PaycorpInvalidResponseException::missingField(
                $operation->value,
                'responseCode'
            );
        }
    }

    private function encryptAes(string $data, string $key, string $ivHex): string
    {
        $ivBytes = hex2bin($ivHex);
        $keyDerived = openssl_pbkdf2($key, $ivBytes, 32, 1000, 'sha1');
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $keyDerived, OPENSSL_RAW_DATA, $ivBytes);

        return base64_encode($encrypted);
    }

    private function getBaseUrl(): string
    {
        if (! empty($this->config['refund_endpoint'])) {
            return rtrim($this->config['refund_endpoint'], '/');
        }

        $endpoint = $this->config['endpoint'] ?? '';

        return rtrim(preg_replace('#/rest/service/proxy$#', '', $endpoint), '/');
    }
}
