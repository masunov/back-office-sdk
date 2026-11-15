<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * The part of a movement or of an issue that was of one lot: a write-off of 1 l from the earliest lots may take 0.75 l
 * of one and 0.25 l of the next. `settledQuantity` — of an issue: what returns and consumptions settled of it.
 */
final class WarehouseLotPart
{
    public function __construct(
        public readonly string $lotId,
        public readonly ?string $number,
        public readonly ?string $expiresOn,
        public readonly string $quantity,
        public readonly ?string $settledQuantity = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['lot_id'], $data['number'] ?? null, $data['expires_on'] ?? null, $data['quantity'], $data['settled_quantity'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @return list<self>
     */
    public static function listFromArray(array $parts): array
    {
        return array_map(fn (array $part): self => self::fromArray($part), $parts);
    }
}
