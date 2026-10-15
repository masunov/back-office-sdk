<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * Stock issued to a person of the project. Also the `data` of the webhook `warehouse.issue.overdue`.
 */
final class WarehouseIssue
{
    public function __construct(
        public readonly string $id,
        public readonly string $itemId,
        public readonly ?string $unitId,
        public readonly string $locationId,
        public readonly string $movementId,
        public readonly ExternalRef $recipient,
        public readonly string $quantity,
        public readonly string $returnedQuantity,
        public readonly ?string $dueAt,
        public readonly ?string $closedAt,
        public readonly bool $overdue,
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
            $data['movement_id'],
            ExternalRef::fromArray($data['recipient']),
            $data['quantity'],
            $data['returned_quantity'],
            $data['due_at'] ?? null,
            $data['closed_at'] ?? null,
            (bool) $data['overdue']
        );
    }

    public function isOpen(): bool
    {
        return $this->closedAt === null;
    }
}
