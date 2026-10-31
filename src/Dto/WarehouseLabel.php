<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A QR label of a serial unit: `code` goes into the QR, `shortCode` (Q-000017) on the sticker for a person to type.
 * `status`: available, assigned, retired; a retired one keeps the unit it was on. `unit` — the card of that unit,
 * only in what `WarehouseApi::resolveLabel()` returns.
 */
final class WarehouseLabel
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $shortCode,
        public readonly string $batchId,
        public readonly string $status,
        public readonly ?string $unitId,
        public readonly ?string $assignedAt,
        public readonly ?string $retiredAt,
        public readonly ?string $retireReason,
        public readonly ?string $comment,
        public readonly ?WarehouseUnit $unit = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['code'],
            $data['short_code'],
            $data['batch_id'],
            $data['status'],
            $data['unit_id'] ?? null,
            $data['assigned_at'] ?? null,
            $data['retired_at'] ?? null,
            $data['retire_reason'] ?? null,
            $data['comment'] ?? null,
            isset($data['unit']) ? WarehouseUnit::fromArray($data['unit']) : null
        );
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }
}
