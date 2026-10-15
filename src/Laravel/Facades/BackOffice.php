<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Mnd\BackOffice\Laravel\BackOfficeManager;

/**
 * @method static \Mnd\BackOffice\BackOfficeClient client()
 * @method static \Mnd\BackOffice\Laravel\Outbox\BackOfficeOutbox outbox()
 * @method static \Mnd\BackOffice\BackOfficeClient forTenant(?string $externalId, ?\Mnd\BackOffice\Dto\Actor $actor = null)
 * @method static \Mnd\BackOffice\Api\TenantsApi tenants(?\Mnd\BackOffice\Dto\Actor $actor = null)
 * @method static \Mnd\BackOffice\Api\FinanceApi finance(?string $tenant, ?\Mnd\BackOffice\Dto\Actor $actor = null)
 * @method static \Mnd\BackOffice\Api\TimetableApi timetable(string $tenant, ?\Mnd\BackOffice\Dto\Actor $actor = null)
 * @method static \Mnd\BackOffice\Api\WarehouseApi warehouse(string $tenant, ?\Mnd\BackOffice\Dto\Actor $actor = null)
 *
 * @see BackOfficeManager
 */
class BackOffice extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BackOfficeManager::class;
    }
}
