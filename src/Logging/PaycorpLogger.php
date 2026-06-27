<?php

namespace Hansajith18\LaravelPaycorp\Logging;

use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;

/**
 * Centralised logger for the Paycorp package.
 *
 * Routes all package output through a single, configurable channel that is
 * registered automatically the first time the logger is used.  If the
 * channel cannot be resolved for any reason (misconfiguration, missing
 * driver, etc.) the call is transparently forwarded to Laravel's default
 * channel so payment flows are never interrupted by a logging failure.
 */
class PaycorpLogger
{
    private ?LoggerInterface $resolved = null;

    public function __construct(
        private readonly LogManager $manager,
        private readonly string $channel,
    ) {}

    public function info(string $message, array $context = []): void
    {
        $this->logger()->info($message, $this->enrich($context));
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger()->warning($message, $this->enrich($context));
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger()->error($message, $this->enrich($context));
    }

    public function debug(string $message, array $context = []): void
    {
        $this->logger()->debug($message, $this->enrich($context));
    }

    /**
     * Resolve the configured channel once and cache it.
     *
     * Falls back to the application's default channel if the paycorp channel
     * cannot be resolved — this prevents any logging misconfiguration from
     * breaking a live payment flow.
     */
    private function logger(): LoggerInterface
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        try {
            $this->resolved = $this->manager->channel($this->channel);
        } catch (\Throwable) {
            $this->resolved = $this->manager->channel(
                $this->manager->getDefaultDriver()
            );
        }

        return $this->resolved;
    }

    /**
     * Stamps every log entry with a consistent "source" field so log
     * aggregators can filter all Paycorp entries across any channel.
     */
    private function enrich(array $context): array
    {
        return array_merge(['source' => 'paycorp'], $context);
    }
}
