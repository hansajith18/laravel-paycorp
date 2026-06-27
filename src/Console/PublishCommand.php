<?php

namespace Hansajith18\LaravelPaycorp\Console;

use Illuminate\Console\Command;

class PublishCommand extends Command
{
    protected $signature = 'paycorp:publish {--force : Overwrite existing files without prompting}';

    protected $description = 'Publish Paycorp config and migrations';

    public function handle(): void
    {
        $this->publishConfig();
        $this->publishMigrations();

        $this->newLine();
        $this->info('✅ Paycorp assets published successfully.');
        $this->newLine();

        $this->printPostInstallGuide();
    }

    private function publishConfig(): void
    {
        $configDest = config_path('paycorp.php');

        if (file_exists($configDest) && ! $this->option('force')) {
            if (! $this->confirm('⚠️  config/paycorp.php already exists. Overwrite?', false)) {
                $this->line('  Skipped config.');

                return;
            }
        }

        $this->callSilently('vendor:publish', [
            '--tag' => 'paycorp-config',
            '--force' => true,
        ]);

        $this->line('  ✔ Config published → config/paycorp.php');
    }

    private function publishMigrations(): void
    {
        $migrationMap = [
            'create_payments_table' => __DIR__ . '/../../database/migrations/create_payments_table.php',
            'create_payment_gateway_logs_table' => __DIR__ . '/../../database/migrations/create_payment_gateway_logs_table.php',
            'create_saved_payments_table' => __DIR__ . '/../../database/migrations/create_saved_payments_table.php',
        ];

        $existing = [];
        $fresh = [];

        foreach ($migrationMap as $name => $sourcePath) {
            $destPath = $this->findExistingMigration($name);

            if ($destPath) {
                $existing[$name] = ['source' => $sourcePath, 'dest' => $destPath];
            } else {
                $fresh[$name] = $sourcePath;
            }
        }

        if (! empty($existing)) {
            if (! $this->option('force')) {
                $this->warn('The following migration(s) already exist:');
                foreach (array_keys($existing) as $name) {
                    $this->line("    - {$name}");
                }

                if (! $this->confirm('Overwrite existing migration(s)?', false)) {
                    $this->line('  Skipped existing migrations.');
                    $this->publishFreshMigrations($fresh);

                    return;
                }
            }

            foreach ($existing as $name => $paths) {
                copy($paths['source'], $paths['dest']);
                $this->line('  ✔ Overwritten: ' . basename($paths['dest']));
            }
        }

        $this->publishFreshMigrations($fresh);

        $this->line('  ✔ Migrations done.');
    }

    private function publishFreshMigrations(array $migrations): void
    {
        $offset = 0;

        foreach ($migrations as $name => $sourcePath) {
            $timestamp = date('Y_m_d_His', time() + $offset++);
            $dest = database_path("migrations/{$timestamp}_{$name}.php");
            copy($sourcePath, $dest);
            $this->line('  ✔ Published: ' . basename($dest));
        }
    }

    private function findExistingMigration(string $name): ?string
    {
        $path = database_path('migrations');

        if (! is_dir($path)) {
            return null;
        }

        foreach (scandir($path) as $file) {
            if (str_contains($file, $name)) {
                return $path . DIRECTORY_SEPARATOR . $file;
            }
        }

        return null;
    }

    /**
     * Print a structured post-install guide covering:
     *  1. Required .env variables
     *  2. Log channel — auto-registered vs. explicit customisation
     */
    private function printPostInstallGuide(): void
    {
        $this->components->info('Post-install checklist');

        // ── 1. Environment variables ──────────────────────────────────────────
        $this->line('  <fg=yellow>①</> Add the following to your <fg=cyan>.env</>:');
        $this->newLine();
        $this->line('     <fg=gray>PAYCORP_ENDPOINT=https://...');
        $this->line('     PAYCORP_AUTH_TOKEN=your_token');
        $this->line('     PAYCORP_HMAC_SECRET=your_secret');
        $this->line('     PAYCORP_CLIENT_ID_LKR=your_client_id');
        $this->line('     PAYCORP_RETURN_URL=https://yourapp.com/payment/callback');
        $this->line('     PAYCORP_SANDBOX=false</>');
        $this->newLine();

        // ── 2. Log channel ────────────────────────────────────────────────────
        $this->line('  <fg=yellow>②</> Log channel (<fg=cyan>paycorp</> → <fg=cyan>storage/logs/paycorp-YYYY-MM-DD.log</>):');
        $this->newLine();

        $hasExplicitChannel = $this->hasExplicitLoggingChannel();

        if ($hasExplicitChannel) {
            $this->line('     <fg=green>✔</> A <fg=cyan>paycorp</> channel is already defined in <fg=cyan>config/logging.php</>.');
        } else {
            $this->line('     <fg=green>✔</> The package auto-registers a <fg=cyan>paycorp</> log channel at boot —');
            $this->line('       no manual configuration is required.');
            $this->newLine();
            $this->line('     To customise the channel (level, retention, driver) add this');
            $this->line('     block to the <fg=cyan>\'channels\'</> array in <fg=cyan>config/logging.php</>:');
            $this->newLine();
            $this->line("     <fg=gray>'paycorp' => [");
            $this->line("         'driver' => 'daily',");
            $this->line("         'path'   => storage_path('logs/paycorp.log'),");
            $this->line("         'level'  => env('PAYCORP_LOG_LEVEL', 'debug'),");
            $this->line("         'days'   => 14,");
            $this->line('     ],</>');
            $this->newLine();
            $this->line('     Or point the package at an existing channel via .env:');
            $this->line('     <fg=gray>PAYCORP_LOG_CHANNEL=stack</>');
        }

        $this->newLine();
        $this->line('  <fg=yellow>③</> Run migrations:');
        $this->line('     <fg=gray>php artisan migrate</>');
        $this->newLine();
    }

    /**
     * Returns true when the parent project has explicitly declared a "paycorp"
     * channel in its own config/logging.php (vs. relying on the package default).
     */
    private function hasExplicitLoggingChannel(): bool
    {
        $loggingFile = config_path('logging.php');

        if (! file_exists($loggingFile)) {
            return false;
        }

        return str_contains(file_get_contents($loggingFile), "'paycorp'")
            || str_contains(file_get_contents($loggingFile), '"paycorp"');
    }
}
