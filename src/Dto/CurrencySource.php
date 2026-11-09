<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A source of rates and how its loading goes: `confirmedThrough` — the last day its rate is known for.
 */
final class CurrencySource
{
    /**
     * @param  list<string>  $currencies  `baseCurrency` first
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly bool $isOfficial,
        public readonly string $baseCurrency,
        public readonly string $timezone,
        public readonly array $currencies,
        public readonly ?string $confirmedThrough,
        public readonly ?string $lastSuccessAt,
        public readonly ?string $lastErrorAt,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['code'],
            $data['name'],
            (bool) $data['is_official'],
            $data['base_currency'],
            $data['timezone'],
            array_values($data['currencies']),
            $data['confirmed_through'] ?? null,
            $data['last_success_at'] ?? null,
            $data['last_error_at'] ?? null
        );
    }
}
