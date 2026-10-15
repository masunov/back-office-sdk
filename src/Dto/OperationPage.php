<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A page of operations; the next one is asked for with `nextCursor`.
 */
final class OperationPage
{
    /**
     * @param  list<FinanceOperation>  $operations
     */
    public function __construct(
        public readonly array $operations,
        public readonly ?string $nextCursor,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(fn (array $item): FinanceOperation => FinanceOperation::fromArray($item), $data['data']),
            $data['meta']['next_cursor'] ?? null
        );
    }
}
