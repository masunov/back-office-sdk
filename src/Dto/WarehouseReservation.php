<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * Stock held at a place for a holder of the project, e.g. a stage: nobody else moves it until it is issued by the
 * reservation, cancelled or expired. Also the `data` of the webhook `warehouse.reservation.changed`, with `action`.
 */
final class WarehouseReservation
{
    public function __construct(
        public readonly string $id,
        public readonly string $itemId,
        public readonly ?string $unitId,
        public readonly string $locationId,
        public readonly ExternalRef $holder,
        public readonly string $quantity,
        public readonly string $issuedQuantity,
        public readonly string $remaining,
        /** active|fulfilled|cancelled|expired */
        public readonly string $status,
        public readonly ?string $expiresAt,
        public readonly ?string $sourceType,
        public readonly ?string $sourceId,
        public readonly ?string $comment,
        public readonly Actor $actor,
        public readonly string $createdAt,
        public readonly ?string $closedAt,
        /** created|issued|fulfilled|cancelled|expired|shortened — only in a webhook */
        public readonly ?string $action = null,
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
            $data['unit_id'] ?? null,
            $data['location_id'],
            ExternalRef::fromArray($data['holder']),
            $data['quantity'],
            $data['issued_quantity'],
            $data['remaining'],
            $data['status'],
            $data['expires_at'] ?? null,
            $data['source_type'] ?? null,
            $data['source_id'] ?? null,
            $data['comment'] ?? null,
            new Actor($data['actor']['id'] ?? null, $data['actor']['name'] ?? null),
            $data['created_at'],
            $data['closed_at'] ?? null,
            $data['action'] ?? null
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
