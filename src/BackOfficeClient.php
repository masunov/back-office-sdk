<?php

declare(strict_types=1);

namespace Mnd\BackOffice;

use GuzzleHttp\Client;
use Mnd\BackOffice\Api\FinanceApi;
use Mnd\BackOffice\Api\TenantsApi;
use Mnd\BackOffice\Api\TimetableApi;
use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Http\CircuitBreaker;
use Mnd\BackOffice\Http\OutgoingRequest;
use Mnd\BackOffice\Http\Transport;

/**
 * The client of back-office. Immutable: `forTenant()` and `actingAs()` return a new client with that context.
 *
 *     $client->forTenant('org-1')->actingAs(new Actor('5', 'Иван'))->finance()->summary([...]);
 */
final class BackOfficeClient
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ?string $tenant = null,
        private readonly ?Actor $actor = null,
    ) {
    }

    /**
     * Without Laravel: a Guzzle client and an optional circuit breaker on any PSR-16 cache.
     */
    public static function create(string $baseUrl, string $key, ?CircuitBreaker $circuitBreaker = null): self
    {
        return new self(new Transport(new Client(), $baseUrl, $key, $circuitBreaker));
    }

    public function forTenant(?string $externalId): self
    {
        return new self($this->transport, $externalId, $this->actor);
    }

    public function actingAs(?Actor $actor): self
    {
        return new self($this->transport, $this->tenant, $actor);
    }

    public function tenants(): TenantsApi
    {
        return new TenantsApi($this->transport, $this->actor);
    }

    public function finance(): FinanceApi
    {
        return new FinanceApi($this->transport, $this->tenant, $this->actor);
    }

    public function timetable(): TimetableApi
    {
        return new TimetableApi($this->transport, $this->tenant, $this->actor);
    }

    /**
     * Sends a prepared request as is, e.g. one the outbox stored.
     *
     * @return array<string, mixed>
     */
    public function send(OutgoingRequest $request): array
    {
        return $this->transport->send($request);
    }
}
