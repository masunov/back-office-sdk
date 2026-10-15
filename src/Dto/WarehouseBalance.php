<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * The balance of an item at a place and the moving average cost of a unit there.
 */
final class WarehouseBalance
{
    public function __construct(
        public readonly string $itemId,
        public readonly string $locationId,
        public readonly string $quantity,
        public readonly string $avgCost,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['item_id'], $data['location_id'], $data['quantity'], $data['avg_cost']);
    }
}
