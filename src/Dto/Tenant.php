<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * An organization of the project in the service.
 */
final class Tenant
{
    public function __construct(
        public readonly string $id,
        public readonly string $externalId,
        public readonly string $name,
        public readonly ?string $region,
        public readonly string $currency,
        public readonly string $timezone,
        public readonly string $status,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['external_id'],
            $data['name'],
            $data['region'] ?? null,
            $data['currency'],
            $data['timezone'],
            $data['status']
        );
    }
}
