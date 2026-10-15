<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A serial unit with its movements and the changes of its condition, oldest first. A condition change is
 * `{from, to, comment, actor: {id, name}, changed_at}`.
 */
final class UnitHistory
{
    /**
     * @param  list<WarehouseMovement>  $movements
     * @param  list<array<string, mixed>>  $conditions
     */
    public function __construct(
        public readonly WarehouseUnit $unit,
        public readonly array $movements,
        public readonly array $conditions,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            WarehouseUnit::fromArray($data['unit']),
            array_map(fn (array $movement): WarehouseMovement => WarehouseMovement::fromArray($movement), $data['movements']),
            array_values($data['conditions'] ?? [])
        );
    }
}
