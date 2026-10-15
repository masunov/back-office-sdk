<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * An item of the nomenclature: counted by quantity or each unit by its serial number.
 */
final class WarehouseItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $category,
        public readonly string $unit,
        public readonly ?string $sku,
        public readonly string $accounting,
        public readonly ?string $minBalance,
        public readonly ?string $photoUrl,
        public readonly bool $archived,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name'],
            $data['category'] ?? null,
            $data['unit'],
            $data['sku'] ?? null,
            $data['accounting'],
            $data['min_balance'] ?? null,
            $data['photo_url'] ?? null,
            (bool) $data['archived']
        );
    }

    public function isSerial(): bool
    {
        return $this->accounting === 'serial';
    }
}
