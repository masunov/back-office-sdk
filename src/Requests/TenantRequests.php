<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Requests;

use Mnd\BackOffice\Http\OutgoingRequest;

/**
 * Requests of tenants (docs/openapi.yaml, /v1/tenants).
 */
final class TenantRequests
{
    /**
     * Creates or updates the tenant; the same body again changes nothing.
     */
    public static function put(
        string $externalId,
        string $name,
        string $currency,
        string $timezone,
        ?string $region = null,
        string $status = 'active',
    ): OutgoingRequest {
        return OutgoingRequest::put(
            '/v1/tenants/' . rawurlencode($externalId),
            [
                'name'     => $name,
                'region'   => $region,
                'currency' => $currency,
                'timezone' => $timezone,
                'status'   => $status,
            ],
            'tenant:' . $externalId
        );
    }

    public static function get(string $externalId): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/tenants/' . rawurlencode($externalId));
    }
}
