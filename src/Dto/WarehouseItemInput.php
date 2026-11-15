<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

use Mnd\BackOffice\Enum\ItemUnit;

/**
 * An item to write. `accounting` — `quantity` or `serial`; quantities are strings with up to 3 decimals. `unit` — an
 * ItemUnit or its code (the service takes «шт» too). A pack or a bottle counted by quantity may hold a weight or a
 * volume: `packageUnit` (kg or l) with `packageSize` (0.75 — a bottle of 0.75 l).
 */
final class WarehouseItemInput
{
    public function __construct(
        public readonly string $name,
        public readonly string|ItemUnit $unit,
        public readonly string $accounting = 'quantity',
        public readonly ?string $category = null,
        public readonly ?string $sku = null,
        public readonly ?string $minBalance = null,
        public readonly ?string $photoUrl = null,
        public readonly string|ItemUnit|null $packageUnit = null,
        public readonly ?string $packageSize = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'unit'         => $this->unit instanceof ItemUnit ? $this->unit->value : $this->unit,
            'accounting'   => $this->accounting,
            'category'     => $this->category,
            'sku'          => $this->sku,
            'min_balance'  => $this->minBalance,
            'photo_url'    => $this->photoUrl,
            'package_unit' => $this->packageUnit instanceof ItemUnit ? $this->packageUnit->value : $this->packageUnit,
            'package_size' => $this->packageSize,
        ];
    }
}
