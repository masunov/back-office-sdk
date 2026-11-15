<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A lot of a quantity item: what came in under one number and one expiry date (`expiresOn` — the last day it is
 * good). A lot without a number or a date is the stock nobody dated. Stock leaves the earliest lot first; an expired
 * one (`expired`, by the day of the tenant) is only written off. Also `lot` in the `data` of the webhooks
 * `warehouse.lot.expiring` and `warehouse.lot.expired`.
 */
final class WarehouseLot
{
    /**
     * @param  list<array{locationId: string, quantity: string}>  $balances  where it is, above zero
     */
    public function __construct(
        public readonly string $id,
        public readonly string $itemId,
        public readonly ?string $number,
        public readonly ?string $expiresOn,
        public readonly bool $expired,
        public readonly string $quantity,
        public readonly array $balances,
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
            $data['number'] ?? null,
            $data['expires_on'] ?? null,
            (bool) $data['expired'],
            $data['quantity'],
            array_map(
                fn (array $balance): array => ['locationId' => $balance['location_id'], 'quantity' => $balance['quantity']],
                $data['balances'] ?? []
            )
        );
    }
}
