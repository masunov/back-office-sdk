<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A serial unit. `locationId` is null while it is issued or after it is written off.
 */
final class WarehouseUnit
{
    public function __construct(
        public readonly string $id,
        public readonly string $itemId,
        public readonly string $serialNumber,
        public readonly string $condition,
        public readonly ?string $locationId,
        public readonly ?string $issueId,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['item_id'],
            $data['serial_number'],
            $data['condition'],
            $data['location_id'] ?? null,
            $data['issue_id'] ?? null
        );
    }
}
