<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Http;

use Psr\SimpleCache\CacheInterface;

final class CircuitBreaker
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $threshold = 5,
        private readonly int $cooldown = 30,
        private readonly string $prefix = 'back-office:circuit:',
    ) {
    }

    public function isOpen(): bool
    {
        $openUntil = $this->cache->get($this->prefix . 'open_until');

        return \is_int($openUntil) && $openUntil > time();
    }

    public function recordSuccess(): void
    {
        $this->cache->deleteMultiple([$this->prefix . 'failures', $this->prefix . 'open_until']);
    }

    public function recordFailure(): void
    {
        // Not atomic: under a race the circuit opens a request later, which is good enough.
        $failures = (int) $this->cache->get($this->prefix . 'failures', 0) + 1;
        $this->cache->set($this->prefix . 'failures', $failures, $this->cooldown * 10);

        if ($failures >= $this->threshold) {
            $this->cache->set($this->prefix . 'open_until', time() + $this->cooldown, $this->cooldown);
        }
    }
}
