<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A place stock lies at: a warehouse, a shelf (parent), a car, a box, «in the field» at a stage.
 */
final class WarehouseLocation
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $parentId,
        public readonly ?ExternalRef $externalRef,
        public readonly bool $archived,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name'],
            $data['kind'],
            $data['parent_id'] ?? null,
            isset($data['external_ref']) ? ExternalRef::fromArray($data['external_ref']) : null,
            (bool) $data['archived']
        );
    }
}
