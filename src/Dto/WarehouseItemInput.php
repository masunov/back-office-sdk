<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * An item to write. `accounting` — `quantity` or `serial`; quantities are strings with up to 3 decimals.
 */
final class WarehouseItemInput
{
    public function __construct(
        public readonly string $name,
        public readonly string $unit,
        public readonly string $accounting = 'quantity',
        public readonly ?string $category = null,
        public readonly ?string $sku = null,
        public readonly ?string $minBalance = null,
        public readonly ?string $photoUrl = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'unit'        => $this->unit,
            'accounting'  => $this->accounting,
            'category'    => $this->category,
            'sku'         => $this->sku,
            'min_balance' => $this->minBalance,
            'photo_url'   => $this->photoUrl,
        ];
    }
}
