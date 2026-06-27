<?php

namespace Hansajith18\LaravelPaycorp\Tests\Unit;

use Hansajith18\LaravelPaycorp\Contracts\PaycorpGatewayInterface;
use Hansajith18\LaravelPaycorp\Enums\PaymentPurposeEnum;
use Hansajith18\LaravelPaycorp\Logging\GatewayAuditLogger;
use Hansajith18\LaravelPaycorp\Logging\PaycorpLogger;
use Hansajith18\LaravelPaycorp\PaycorpClient;
use Hansajith18\LaravelPaycorp\Services\PaycenterPaymentService;
use Hansajith18\LaravelPaycorp\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged_into_application(): void
    {
        $this->assertNotNull(config('paycorp'));
        $this->assertIsArray(config('paycorp'));
    }

    public function test_config_has_expected_keys(): void
    {
        $config = config('paycorp');

        $expectedKeys = [
            'endpoint', 'auth_token', 'hmac_secret',
            'client_id_lkr', 'client_id_usd',
            'default_currency', 'return_url',
            'timeout', 'connect_timeout',
            'retry_times', 'retry_sleep_ms',
            'api_version',
            'refund_endpoint', 'refund_username', 'refund_password',
            'refund_iv_phrase', 'refund_aes_secret', 'refund_device_id',
            'sandbox',
            'log_channel', 'log_level', 'log_days',
            'user_model',
            'payable_status_classes',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $config, "Config key [{$key}] is missing.");
        }
    }

    public function test_config_defaults_are_safe(): void
    {
        // Credential defaults must be null (populated only from env in production).
        // TestCase sets endpoint/auth_token/hmac_secret for test isolation — skip those.
        // Refund secrets must never have hardcoded defaults.
        $this->assertNull(config('paycorp.refund_iv_phrase'));
        $this->assertNull(config('paycorp.refund_aes_secret'));
        $this->assertNull(config('paycorp.refund_device_id'));
        $this->assertFalse(config('paycorp.sandbox'));
    }

    public function test_paycorp_logger_is_resolved_from_container(): void
    {
        $logger = $this->app->make(PaycorpLogger::class);

        $this->assertInstanceOf(PaycorpLogger::class, $logger);
    }

    public function test_gateway_interface_is_bound_in_container(): void
    {
        $gateway = $this->app->make(PaycorpGatewayInterface::class);

        $this->assertInstanceOf(PaycorpGatewayInterface::class, $gateway);
    }

    public function test_paycorp_client_is_resolved_from_container(): void
    {
        $client = $this->app->make(PaycorpClient::class);

        $this->assertInstanceOf(PaycorpClient::class, $client);
    }

    public function test_paycorp_short_alias_resolves_to_client(): void
    {
        $client = $this->app->make('paycorp');

        $this->assertInstanceOf(PaycorpClient::class, $client);
    }

    public function test_gateway_audit_logger_is_resolved_from_container(): void
    {
        $audit = $this->app->make(GatewayAuditLogger::class);

        $this->assertInstanceOf(GatewayAuditLogger::class, $audit);
    }

    public function test_payment_service_is_resolved_from_container(): void
    {
        $service = $this->app->make(PaycenterPaymentService::class);

        $this->assertInstanceOf(PaycenterPaymentService::class, $service);
    }

    public function test_payable_status_classes_defaults_to_empty_array(): void
    {
        $this->assertSame([], config('paycorp.payable_status_classes'));
    }

    public function test_log_channel_defaults_to_paycorp(): void
    {
        $this->assertSame('paycorp', config('paycorp.log_channel'));
    }

    public function test_default_currency_is_lkr(): void
    {
        $this->assertSame('LKR', config('paycorp.default_currency'));
    }

    public function test_purpose_class_defaults_to_built_in_enum(): void
    {
        $this->assertSame(
            PaymentPurposeEnum::class,
            config('paycorp.purpose_class')
        );
    }

    public function test_card_registration_purpose_defaults_to_string_value(): void
    {
        $this->assertSame('card_registration', config('paycorp.card_registration_purpose'));
    }

    public function test_payable_status_column_defaults_to_payment_status(): void
    {
        $this->assertSame('payment_status', config('paycorp.payable_status_column'));
    }
}
