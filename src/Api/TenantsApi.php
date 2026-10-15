<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Api;

use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\Tenant;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\TenantRequests;

final class TenantsApi
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ?Actor $actor,
    ) {
    }

    public function put(
        string $externalId,
        string $name,
        string $currency,
        string $timezone,
        ?string $region = null,
        string $status = 'active',
    ): Tenant {
        $request = TenantRequests::put($externalId, $name, $currency, $timezone, $region, $status)->actingAs($this->actor);

        return Tenant::fromArray($this->transport->send($request)['data']);
    }

    public function get(string $externalId): Tenant
    {
        return Tenant::fromArray($this->transport->send(TenantRequests::get($externalId))['data']);
    }
}
