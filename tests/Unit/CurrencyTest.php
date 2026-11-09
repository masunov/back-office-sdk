<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Exceptions\ApiErrorException;
use Mnd\BackOffice\Http\Transport;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    use MocksHttp;

    private function client(): BackOfficeClient
    {
        return new BackOfficeClient(new Transport($this->mockHttp(), 'https://bo.test', 'bo_key', null, []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function rateData(array $overrides = []): array
    {
        return $overrides + [
            'source'         => 'cbr',
            'is_official'    => true,
            'base'           => 'USD',
            'quote'          => 'RUB',
            'rate'           => '82.138600000000',
            'requested_date' => '2026-11-09',
            'rate_date'      => '2026-11-07',
            'rate_set_id'    => '01a15000-0000-7000-8000-000000000001',
            'fetched_at'     => '2026-11-07T12:40:00+00:00',
            'derived'        => false,
        ];
    }

    public function testRatesAreSentWithoutTenantAndKeptAsStrings(): void
    {
        $client = $this->client();
        $this->mock->append(self::jsonResponse(200, ['data' => [self::rateData(), self::rateData(['quote' => 'EUR', 'rate' => '0.890947626919', 'derived' => true])]]));

        $rates = $client->forTenant('org-1')->actingAs(new Actor('1', 'Иван'))->currency()->rates('cbr', 'USD', ['RUB', 'EUR'], '2026-11-09');

        $request = $this->sentRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/currency/rates', $request->getUri()->getPath());
        $this->assertSame('source=cbr&base=USD&quotes%5B%5D=RUB&quotes%5B%5D=EUR&date=2026-11-09', $request->getUri()->getQuery());
        $this->assertFalse($request->hasHeader('X-Tenant'));
        $this->assertSame(['RUB', 'EUR'], array_keys($rates));
        $this->assertSame('82.138600000000', $rates['RUB']->rate);
        $this->assertSame('2026-11-07', $rates['RUB']->rateDate);
        $this->assertTrue($rates['EUR']->derived);
    }

    public function testTheHistoryGoesThroughEveryPage(): void
    {
        $client = $this->client();
        $row    = fn (string $date): array => ['rate_date' => $date, 'valid_through' => $date, 'rate' => '0.012174544002', 'rate_set_id' => 'set-' . $date, 'derived' => true];
        $this->mock->append(
            self::jsonResponse(200, ['data' => [$row('2026-11-05'), $row('2026-11-06')], 'meta' => ['next_cursor' => 'c2']]),
            self::jsonResponse(200, ['data' => [$row('2026-11-07')], 'meta' => ['next_cursor' => null]])
        );

        $rates = iterator_to_array($client->currency()->history('cbr', 'RUB', 'USD', '2026-11-01', '2026-11-30', 2), false);

        $this->assertSame(['2026-11-05', '2026-11-06', '2026-11-07'], array_map(fn ($rate): string => $rate->rateDate, $rates));
        $this->assertStringNotContainsString('cursor', $this->sentRequest(0)->getUri()->getQuery());
        $this->assertStringContainsString('per_page=2&cursor=c2', $this->sentRequest(1)->getUri()->getQuery());
    }

    public function testConvertKeepsTheSnapshot(): void
    {
        $client = $this->client();
        $this->mock->append(
            self::jsonResponse(
                200,
                ['data' => [
                    'original_amount'   => '100.00',
                    'original_currency' => 'USD',
                    'amount'            => '8000.00',
                    'currency'          => 'RUB',
                    'rate'              => '80.000000000000',
                    'source'            => 'manual',
                    'is_official'       => false,
                    'requested_date'    => '2026-11-09',
                    'rate_date'         => '2026-11-09',
                    'rate_set_id'       => null,
                    'fetched_at'        => null,
                    'derived'           => false,
                    'rounding'          => 'half_up',
                    'scale'             => 2,
                ]]
            )
        );

        $conversion = $client->currency()->convert('manual', '100', 'USD', 'RUB', '2026-11-09', '80');

        $this->assertSame('source=manual&amount=100&from=USD&to=RUB&date=2026-11-09&rate=80', $this->sentRequest()->getUri()->getQuery());
        $this->assertSame('8000.00', $conversion->amount);
        $this->assertSame(['USD', 'RUB', '80.000000000000', null], [$conversion->rate->base, $conversion->rate->quote, $conversion->rate->rate, $conversion->rate->rateSetId]);
    }

    public function testSourcesAndCurrencies(): void
    {
        $client = $this->client();
        $this->mock->append(
            self::jsonResponse(200, ['data' => [['code' => 'RUB', 'type' => 'fiat', 'sources' => ['cbr', 'nbrb']], ['code' => 'UZS', 'type' => 'fiat', 'sources' => ['cbr']]]]),
            self::jsonResponse(
                200,
                ['data' => [[
                'code'              => 'nbrb',
                'name'              => 'Национальный банк Республики Беларусь',
                'is_official'       => true,
                'base_currency'     => 'BYN',
                'timezone'          => 'Europe/Minsk',
                'currencies'        => ['BYN', 'RUB'],
                'confirmed_through' => '2026-11-10',
                'last_success_at'   => '2026-11-09T09:10:00+00:00',
                'last_error_at'     => null,
                ]]]
            )
        );

        $this->assertSame(['RUB' => ['cbr', 'nbrb'], 'UZS' => ['cbr']], $client->currency()->currencies());
        $source = $client->currency()->sources()[0];
        $this->assertSame(['nbrb', 'BYN', '2026-11-10', null], [$source->code, $source->baseCurrency, $source->confirmedThrough, $source->lastErrorAt]);
    }

    public function testErrorsOfRates(): void
    {
        $client = $this->client();
        $this->mock->append(
            self::apiError(404, 'rate_not_found', ['confirmed_through' => '2026-11-09', 'missing' => ['USD/RUB']]),
            self::apiError(409, 'rate_stale', ['confirmed_through' => '2026-11-06'])
        );

        try {
            $client->currency()->rate('cbr', 'USD', 'RUB', '2026-11-10');
            $this->fail('rate_not_found expected');
        } catch (ApiErrorException $e) {
            $this->assertSame(['rate_not_found', '2026-11-09'], [$e->errorCode(), $e->details()['confirmed_through']]);
            $this->assertFalse($e->isRetriable());
        }

        try {
            $client->currency()->rate('cbr', 'USD', 'RUB');
            $this->fail('rate_stale expected');
        } catch (ApiErrorException $e) {
            $this->assertSame('rate_stale', $e->errorCode());
            $this->assertTrue($e->isRetriable());
        }
    }
}
