<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Api;

use Generator;
use Mnd\BackOffice\Dto\CurrencyConversion;
use Mnd\BackOffice\Dto\CurrencyHistoryRate;
use Mnd\BackOffice\Dto\CurrencyRate;
use Mnd\BackOffice\Dto\CurrencySource;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\CurrencyRequests;

/**
 * Rates of currencies: the official rates of the Bank of Russia (`cbr`) and the NBRB (`nbrb`), common to every
 * project — the tenant of the client is not sent. `source` is always explicit: a rate never comes from another one.
 */
final class CurrencyApi
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @return array<string, list<string>> currency => the sources that quote it
     */
    public function currencies(): array
    {
        $result = [];

        foreach ($this->transport->send(CurrencyRequests::currencies())['data'] as $currency) {
            $result[$currency['code']] = array_values($currency['sources']);
        }

        return $result;
    }

    /**
     * @return list<CurrencySource>
     */
    public function sources(): array
    {
        return array_map(
            fn (array $source): CurrencySource => CurrencySource::fromArray($source),
            $this->transport->send(CurrencyRequests::sources())['data']
        );
    }

    /**
     * Rates of the base to each quote on the date, today of the source without it. A pair the source has no rate
     * of fails the whole request (`rate_not_found`, field `missing`).
     *
     * @param  list<string>  $quotes  up to 50
     * @return array<string, CurrencyRate> quote => rate
     */
    public function rates(string $source, string $base, array $quotes, ?string $date = null): array
    {
        $result = [];

        foreach ($this->transport->send(CurrencyRequests::rates($source, $base, $quotes, $date))['data'] as $rate) {
            $result[$rate['quote']] = CurrencyRate::fromArray($rate);
        }

        return $result;
    }

    public function rate(string $source, string $base, string $quote, ?string $date = null): CurrencyRate
    {
        return $this->rates($source, $base, [$quote], $date)[$quote];
    }

    /**
     * A page of the history of a pair (the period is at most 366 days) and the cursor of the next one.
     *
     * @return array{0: list<CurrencyHistoryRate>, 1: string|null}
     */
    public function historyPage(
        string $source,
        string $base,
        string $quote,
        string $from,
        string $to,
        ?int $perPage = null,
        ?string $cursor = null,
    ): array {
        $data = $this->transport->send(CurrencyRequests::history($source, $base, $quote, $from, $to, $perPage, $cursor));

        return [
            array_map(fn (array $rate): CurrencyHistoryRate => CurrencyHistoryRate::fromArray($rate), $data['data']),
            $data['meta']['next_cursor'] ?? null,
        ];
    }

    /**
     * Every rate of the period, page after page.
     *
     * @return Generator<CurrencyHistoryRate>
     */
    public function history(string $source, string $base, string $quote, string $from, string $to, int $perPage = 200): Generator
    {
        $cursor = null;

        do {
            [$rates, $cursor] = $this->historyPage($source, $base, $quote, $from, $to, $perPage, $cursor);

            // Not `yield from`: the keys of every page start at 0.
            foreach ($rates as $rate) {
                yield $rate;
            }
        } while ($cursor !== null);
    }

    /**
     * Converts an amount (a decimal string, 2 digits at most) by the rate of the date; `source: manual` — by the
     * given rate, the date required.
     */
    public function convert(
        string $source,
        string $amount,
        string $from,
        string $to,
        ?string $date = null,
        ?string $rate = null,
    ): CurrencyConversion {
        return CurrencyConversion::fromArray(
            $this->transport->send(CurrencyRequests::convert($source, $amount, $from, $to, $date, $rate))['data']
        );
    }
}
