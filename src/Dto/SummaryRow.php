<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A category in the summary: its own amounts, without subcategories.
 */
final class SummaryRow
{
    public function __construct(
        public readonly FinanceCategory $category,
        public readonly Totals $totals,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(FinanceCategory::fromArray($data), Totals::fromArray($data));
    }
}
