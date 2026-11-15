<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

use Mnd\BackOffice\Enum\ItemUnit;

/**
 * An item of the nomenclature: counted by quantity or each unit by its serial number. `unit` is a code of ItemUnit,
 * `unitLabel` — how to show it. A pack or a bottle may hold a weight or a volume (`packageUnit`, `packageSize`):
 * then every quantity of the item — balances, movements, issues, reservations, `minBalance` — is in `stockUnit`
 * (kg or l), and packages are only a way to enter it (`packages` of the movements). `shelfLifeDays` dates a receipt
 * that comes without `expiresOn`.
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
        public readonly ?string $unitLabel = null,
        public readonly ?string $packageUnit = null,
        public readonly ?string $packageSize = null,
        public readonly ?string $stockUnit = null,
        public readonly ?string $stockUnitLabel = null,
        public readonly ?int $shelfLifeDays = null,
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
            (bool) $data['archived'],
            $data['unit_label'] ?? null,
            $data['package_unit'] ?? null,
            $data['package_size'] ?? null,
            $data['stock_unit'] ?? $data['unit'],
            $data['stock_unit_label'] ?? null,
            isset($data['shelf_life_days']) ? (int) $data['shelf_life_days'] : null
        );
    }

    /**
     * The unit of the list; null for a unit an older service still keeps as free text.
     */
    public function itemUnit(): ?ItemUnit
    {
        return ItemUnit::fromInput($this->unit);
    }

    /**
     * What the quantities of the item are in.
     */
    public function stockItemUnit(): ?ItemUnit
    {
        return ItemUnit::fromInput($this->stockUnit ?? $this->unit);
    }

    public function isPackaged(): bool
    {
        return $this->packageUnit !== null && $this->packageSize !== null;
    }

    public function isSerial(): bool
    {
        return $this->accounting === 'serial';
    }
}
