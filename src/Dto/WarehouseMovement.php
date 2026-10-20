<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A movement of stock. Also the `data` of the webhook `warehouse.movement.created`, where `balances` are the
 * balances of its places after it.
 */
final class WarehouseMovement
{
    /**
     * @param  list<WarehouseBalance>  $balances
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $itemId,
        public readonly ?string $unitId,
        public readonly string $quantity,
        public readonly ?string $fromLocationId,
        public readonly ?string $toLocationId,
        public readonly ?string $issueId,
        public readonly ?string $unitCost,
        public readonly ?string $totalCost,
        public readonly ?string $sourceType,
        public readonly ?string $sourceId,
        public readonly ?ExternalRef $externalRef,
        public readonly ?string $comment,
        public readonly Actor $actor,
        public readonly string $occurredAt,
        public readonly array $balances = [],
        public readonly ?string $reservationId = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['type'],
            $data['item_id'],
            $data['unit_id'] ?? null,
            $data['quantity'],
            $data['from_location_id'] ?? null,
            $data['to_location_id'] ?? null,
            $data['issue_id'] ?? null,
            $data['unit_cost'] ?? null,
            $data['total_cost'] ?? null,
            $data['source_type'] ?? null,
            $data['source_id'] ?? null,
            isset($data['external_ref']) ? ExternalRef::fromArray($data['external_ref']) : null,
            $data['comment'] ?? null,
            new Actor($data['actor']['id'] ?? null, $data['actor']['name'] ?? null),
            $data['occurred_at'],
            array_map(fn (array $balance): WarehouseBalance => WarehouseBalance::fromArray($balance), $data['balances'] ?? []),
            $data['reservation_id'] ?? null
        );
    }
}
