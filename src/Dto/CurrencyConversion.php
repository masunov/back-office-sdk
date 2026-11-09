<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * An amount converted by the rate of a snapshot, half-up to `scale` digits. Keep `amount` together with `rate` and
 * `rate.rateSetId`: a repeat of the business operation uses them, not a new rate.
 */
final class CurrencyConversion
{
    public function __construct(
        public readonly string $originalAmount,
        public readonly string $originalCurrency,
        public readonly string $amount,
        public readonly string $currency,
        public readonly CurrencyRate $rate,
        public readonly string $rounding,
        public readonly int $scale,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['original_amount'],
            $data['original_currency'],
            (string) $data['amount'],
            $data['currency'],
            CurrencyRate::fromArray(['base' => $data['original_currency'], 'quote' => $data['currency']] + $data),
            $data['rounding'],
            (int) $data['scale']
        );
    }
}
