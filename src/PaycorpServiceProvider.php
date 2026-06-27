<?php

namespace Hansajith18\LaravelPaycorp;

use Hansajith18\LaravelPaycorp\Console\PublishCommand;
use Hansajith18\LaravelPaycorp\Contracts\PaycorpGatewayInterface;
use Hansajith18\LaravelPaycorp\Contracts\PaycorpRefundGatewayInterface;
use Hansajith18\LaravelPaycorp\Gateway\PaycorpHttpGateway;
use Hansajith18\LaravelPaycorp\Gateway\PaycorpRefundGateway;
use Hansajith18\LaravelPaycorp\Logging\GatewayAuditLogger;
use Hansajith18\LaravelPaycorp\Logging\PaycorpLogger;
use Hansajith18\LaravelPaycorp\Services\PaycenterPaymentService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;

class PaycorpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/paycorp.php', 'paycorp');

        $this->registerLogger();
        $this->registerGateways();
        $this->registerPaymentService();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PublishCommand::class]);

            $this->publishes([
                __DIR__ . '/../config/paycorp.php' => config_path('paycorp.php'),
            ], 'paycorp-config');

            $this->publishMigrations();
        }
    }

    private function registerLogger(): void
    {
        $this->app->singleton(PaycorpLogger::class, function ($app) {
            $channel = $app['config']->get('paycorp.log_channel', 'paycorp');

            // Auto-register the "paycorp" channel when the parent project has not defined it
            // in logging.php AND the configured channel name is still the default "paycorp".
            // Any other value means the developer explicitly pointed the package at an existing
            // channel (e.g. PAYCORP_LOG_CHANNEL=stack) — leave that alone.
            if ($channel === 'paycorp' && ! $app['config']->has('logging.channels.paycorp')) {
                $app['config']->set('logging.channels.paycorp', [
                    'driver' => 'daily',
                    'path' => storage_path('logs/paycorp.log'),
                    'level' => $app['config']->get('paycorp.log_level', 'debug'),
                    'days' => $app['config']->get('paycorp.log_days', 14),
                ]);
            }

            return new PaycorpLogger($app['log'], $channel);
        });
    }

    private function registerGateways(): void
    {
        $this->app->singleton(PaycorpGatewayInterface::class, function ($app) {
            $config = $app['config']['paycorp'];
            $this->validateConfig($config);

            return new PaycorpHttpGateway($config, $app->make(PaycorpLogger::class));
        });

        $this->app->singleton(PaycorpRefundGatewayInterface::class, function ($app) {
            $config = $app['config']['paycorp'];

            if (empty($config['refund_username']) || empty($config['refund_password']) || empty($config['refund_iv_phrase']) || empty($config['refund_aes_secret'])) {
                return null;
            }

            return new PaycorpRefundGateway($config, $app->make(PaycorpLogger::class));
        });

        $this->app->singleton(PaycorpClient::class, function ($app) {
            return new PaycorpClient(
                gateway: $app->make(PaycorpGatewayInterface::class),
                refundGateway: $app->make(PaycorpRefundGatewayInterface::class),
            );
        });

        // Short alias so developers can resolve the client via app('paycorp')
        $this->app->alias(PaycorpClient::class, 'paycorp');
    }

    private function registerPaymentService(): void
    {
        $this->app->singleton(GatewayAuditLogger::class);

        $this->app->singleton(PaycenterPaymentService::class, function ($app) {
            return new PaycenterPaymentService(
                client: $app->make(PaycorpClient::class),
                audit: $app->make(GatewayAuditLogger::class),
                logger: $app->make(PaycorpLogger::class),
            );
        });
    }

    private function publishMigrations(): void
    {
        $migrations = [];

        if (! $this->migrationExists('create_payments_table')) {
            $migrations[__DIR__ . '/../database/migrations/create_payments_table.php'] =
                database_path('migrations/' . date('Y_m_d_His') . '_create_payments_table.php');
        }

        if (! $this->migrationExists('create_payment_gateway_logs_table')) {
            $migrations[__DIR__ . '/../database/migrations/create_payment_gateway_logs_table.php'] =
                database_path('migrations/' . date('Y_m_d_His', time() + 1) . '_create_payment_gateway_logs_table.php');
        }

        if (! $this->migrationExists('create_saved_payments_table')) {
            $migrations[__DIR__ . '/../database/migrations/create_saved_payments_table.php'] =
                database_path('migrations/' . date('Y_m_d_His', time() + 2) . '_create_saved_payments_table.php');
        }

        if (! empty($migrations)) {
            $this->publishes($migrations, 'paycorp-migrations');
        }
    }

    private function migrationExists(string $migrationName): bool
    {
        $path = database_path('migrations');

        if (! is_dir($path)) {
            return false;
        }

        foreach (scandir($path) as $file) {
            if (str_contains($file, $migrationName)) {
                return true;
            }
        }

        return false;
    }

    private function validateConfig(array $config): void
    {
        $required = ['endpoint', 'auth_token', 'hmac_secret'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw ValidationException::withMessages([
                    $key => ["Paycorp config [{$key}] is required. Set the corresponding PAYCORP_* environment variable."],
                ]);
            }
        }

        if (! str_starts_with($config['endpoint'], 'https://')) {
            throw ValidationException::withMessages([
                $key => ['Paycorp endpoint must use HTTPS for PCI DSS compliance.'],
            ]);
        }
    }
}
