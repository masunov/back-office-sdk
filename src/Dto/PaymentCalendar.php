<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * The payment calendar: what is left to pay or to get, line by line of the schedules, after the paid amount went to
 * the earliest lines. A need, not a cash gap. Each line has `bucket`: `overdue`, `in_period` or `undated`; group them
 * by counterparty, object or dates as the screen needs.
 *
 * `totals[expense|income]`: overdue, in_period, undated, paid_in_period — money moved in the period, without barter;
 * it may be negative.
 */
final class PaymentCalendar
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, array<string, string>>  $totals
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $totals,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_values($data['lines'] ?? []), $data['totals'] ?? []);
    }

    /**
     * What is left of the lines of a bucket in one direction.
     */
    public function total(string $direction, string $bucket): string
    {
        return $this->totals[$direction][$bucket] ?? '0.00';
    }
}
