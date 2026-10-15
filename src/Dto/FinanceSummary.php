<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * Plan, fact and deviation by category over a set of external refs.
 */
final class FinanceSummary
{
    /**
     * @param  list<ExternalRef>  $externalRefs
     * @param  list<SummaryRow>  $rows
     */
    public function __construct(
        public readonly string $currency,
        public readonly array $externalRefs,
        public readonly array $rows,
        public readonly Totals $income,
        public readonly Totals $expense,
        public readonly Totals $result,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['currency'],
            array_map(fn (array $ref): ExternalRef => ExternalRef::fromArray($ref), $data['external_refs']),
            array_map(fn (array $row): SummaryRow => SummaryRow::fromArray($row), $data['categories']),
            Totals::fromArray($data['totals']['income']),
            Totals::fromArray($data['totals']['expense']),
            Totals::fromArray($data['totals']['result'])
        );
    }

    public function row(string $categoryCode): ?SummaryRow
    {
        foreach ($this->rows as $row) {
            if ($row->category->code === $categoryCode) {
                return $row;
            }
        }

        return null;
    }
}
