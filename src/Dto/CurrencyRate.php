<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A rate of a source on a date: how much `quote` for 1 `base`, a decimal string of 12 digits — never a float.
 * `rateSetId` is the snapshot to keep next to an amount converted by it; `derived` — reverse or cross, not set by
 * the source as is.
 */
final class CurrencyRate
{
    public function __construct(
        public readonly string $source,
        public readonly bool $isOfficial,
        public readonly string $base,
        public readonly string $quote,
        public readonly string $rate,
        public readonly string $requestedDate,
        public readonly string $rateDate,
        public readonly ?string $rateSetId,
        public readonly ?string $fetchedAt,
        public readonly bool $derived,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['source'],
            (bool) $data['is_official'],
            $data['base'],
            $data['quote'],
            (string) $data['rate'],
            $data['requested_date'],
            $data['rate_date'],
            $data['rate_set_id'] ?? null,
            $data['fetched_at'] ?? null,
            (bool) $data['derived']
        );
    }
}
