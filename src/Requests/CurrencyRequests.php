<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Requests;

use Mnd\BackOffice\Http\OutgoingRequest;

/**
 * Requests of the rates of currencies (docs/openapi.yaml, /v1/currency): common to every project, no tenant.
 */
final class CurrencyRequests
{
    public static function currencies(): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/currency/currencies');
    }

    public static function sources(): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/currency/sources');
    }

    /**
     * @param  list<string>  $quotes  up to 50
     */
    public static function rates(string $source, string $base, array $quotes, ?string $date = null): OutgoingRequest
    {
        return OutgoingRequest::read(
            '/v1/currency/rates',
            ['source' => $source, 'base' => $base, 'quotes' => array_values($quotes), 'date' => $date]
        );
    }

    public static function history(
        string $source,
        string $base,
        string $quote,
        string $from,
        string $to,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/currency/rates/history',
            [
                'source'   => $source,
                'base'     => $base,
                'quote'    => $quote,
                'from'     => $from,
                'to'       => $to,
                'per_page' => $perPage,
                'cursor'   => $cursor,
            ]
        );
    }

    /**
     * @param  string|null  $rate  only with `source: manual`, which also needs the date
     */
    public static function convert(
        string $source,
        string $amount,
        string $from,
        string $to,
        ?string $date = null,
        ?string $rate = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/currency/convert',
            ['source' => $source, 'amount' => $amount, 'from' => $from, 'to' => $to, 'date' => $date, 'rate' => $rate]
        );
    }
}
