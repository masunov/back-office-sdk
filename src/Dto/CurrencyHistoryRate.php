<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A rate of a pair in the history: in effect from `rateDate` through `validThrough`.
 */
final class CurrencyHistoryRate
{
    public function __construct(
        public readonly string $rateDate,
        public readonly string $validThrough,
        public readonly string $rate,
        public readonly string $rateSetId,
        public readonly bool $derived,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['rate_date'], $data['valid_through'], (string) $data['rate'], $data['rate_set_id'], (bool) $data['derived']);
    }
}
