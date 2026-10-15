<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A category of income or expense: of the project's set (`level: project`) or a subcategory of a tenant.
 */
final class FinanceCategory
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $direction,
        public readonly string $level,
        public readonly ?string $parentId,
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
            $data['code'],
            $data['name'],
            $data['direction'],
            $data['level'],
            $data['parent_id'] ?? null,
            (bool) $data['archived']
        );
    }
}
