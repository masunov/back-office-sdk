<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel;

use Mnd\BackOffice\Api\FinanceApi;
use Mnd\BackOffice\Api\TenantsApi;
use Mnd\BackOffice\Api\TimetableApi;
use Mnd\BackOffice\Api\WarehouseApi;
use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Laravel\Outbox\BackOfficeOutbox;

/**
 * What the facade BackOffice gives: the client for reads and waited-for writes, the outbox for the rest.
 */
class BackOfficeManager
{
    public function __construct(
        private readonly BackOfficeClient $client,
        private readonly BackOfficeOutbox $outbox,
    ) {
    }

    public function client(): BackOfficeClient
    {
        return $this->client;
    }

    public function outbox(): BackOfficeOutbox
    {
        return $this->outbox;
    }

    public function forTenant(?string $externalId, ?Actor $actor = null): BackOfficeClient
    {
        return $this->client->forTenant($externalId)->actingAs($actor);
    }

    public function tenants(?Actor $actor = null): TenantsApi
    {
        return $this->client->actingAs($actor)->tenants();
    }

    public function finance(?string $tenant, ?Actor $actor = null): FinanceApi
    {
        return $this->forTenant($tenant, $actor)->finance();
    }

    public function timetable(string $tenant, ?Actor $actor = null): TimetableApi
    {
        return $this->forTenant($tenant, $actor)->timetable();
    }

    public function warehouse(string $tenant, ?Actor $actor = null): WarehouseApi
    {
        return $this->forTenant($tenant, $actor)->warehouse();
    }
}
