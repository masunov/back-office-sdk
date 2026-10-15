<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Exceptions\ApiErrorException;
use Mnd\BackOffice\Exceptions\CircuitOpenException;
use Mnd\BackOffice\Exceptions\ServiceUnavailableException;
use Mnd\BackOffice\Http\CircuitBreaker;
use Mnd\BackOffice\Http\Transport;
use PHPUnit\Framework\TestCase;

class TransportTest extends TestCase
{
    use MocksHttp;

    private CircuitBreaker $circuitBreaker;

    private function client(): BackOfficeClient
    {
        $this->circuitBreaker = new CircuitBreaker(new Repository(new ArrayStore()), 3, 30);

        return new BackOfficeClient(
            new Transport($this->mockHttp(), 'https://bo.test/', 'bo_key', $this->circuitBreaker, [1, 1])
        );
    }

    public function testWriteCarriesKeyContextAndIdempotencyKey(): void
    {
        $client = $this->client();
        $this->mock->append(self::jsonResponse(201, ['data' => self::operationData()]));

        $operation = $client->forTenant('org-1')->actingAs(new Actor('5', 'Иван'))->finance()->createOperation(
            'entry_fee',
            '1500.00',
            '2026-10-10',
            'stage_entry_fee',
            '7',
            new ExternalRef('stage', '1', 'Этап 1')
        );

        $request = $this->sentRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://bo.test/v1/finance/operations', (string) $request->getUri());
        $this->assertSame('Bearer bo_key', $request->getHeaderLine('Authorization'));
        $this->assertSame('org-1', $request->getHeaderLine('X-Tenant'));
        $this->assertSame('5', $request->getHeaderLine('X-Actor-Id'));
        $this->assertSame('Иван', $request->getHeaderLine('X-Actor-Name'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $request->getHeaderLine('Idempotency-Key'));
        $this->assertSame(
            [
                'category'       => 'entry_fee',
                'amount'         => '1500.00',
                'operation_date' => '2026-10-10',
                'kind'           => 'regular',
                'source_type'    => 'stage_entry_fee',
                'source_id'      => '7',
                'external_ref'   => ['type' => 'stage', 'id' => '1', 'label' => 'Этап 1'],
            ],
            json_decode((string) $request->getBody(), true)
        );

        $this->assertSame('1500.00', $operation->amount);
        $this->assertSame('stage', $operation->externalRef?->type);
        $this->assertSame('Иван', $operation->actor->name);
    }

    public function testArrayQueryIsSentTheWayLaravelReadsIt(): void
    {
        $client = $this->client();
        $this->mock->append(self::jsonResponse(200, ['data' => $this->summaryData()]));

        $summary = $client->forTenant('org-1')->finance()->summary(
            [new ExternalRef('stage', '1'), new ExternalRef('championship', '1')],
            '2026-10-01'
        );

        $this->assertSame(
            'refs%5B%5D=stage%3A1&refs%5B%5D=championship%3A1&from=2026-10-01',
            $this->sentRequest()->getUri()->getQuery()
        );
        $this->assertSame('6000.00', $summary->row('entry_fee')?->totals->fact);
        $this->assertSame('-4000.00', $summary->income->deviation);
    }

    public function testRefusalBecomesApiErrorWithDetails(): void
    {
        $client = $this->client();
        $this->mock->append(self::apiError(409, 'period_locked', ['external_ref' => ['type' => 'stage', 'id' => '1']]));

        try {
            $client->forTenant('org-1')->finance()->createOperation('entry_fee', '1', '2026-10-10');
            $this->fail('No exception');
        } catch (ApiErrorException $e) {
            $this->assertSame(409, $e->status());
            $this->assertSame('period_locked', $e->errorCode());
            $this->assertSame(['external_ref' => ['type' => 'stage', 'id' => '1']], $e->details());
            $this->assertFalse($e->isRetriable());
        }
    }

    public function testReadIsRetriedWriteIsNot(): void
    {
        $client = $this->client();
        $this->mock->append(self::jsonResponse(503, null), self::jsonResponse(200, ['data' => self::operationData()]));

        $client->forTenant('org-1')->finance()->getOperation('01920000-0000-7000-8000-000000000001');
        $this->assertCount(2, $this->history);

        $this->mock->append(new ConnectException('refused', new Request('POST', '/')));
        $this->expectException(ServiceUnavailableException::class);

        try {
            $client->forTenant('org-1')->finance()->createOperation('entry_fee', '1', '2026-10-10');
        } finally {
            $this->assertCount(3, $this->history);
        }
    }

    public function testCircuitOpensAfterFailuresAndClosesOnSuccess(): void
    {
        $client = $this->client();
        $this->mock->append(self::jsonResponse(502, null), self::jsonResponse(502, null), self::jsonResponse(502, null));

        // Three failures in a row: the circuit is open now.
        $this->assertFailsUnavailable(fn () => $client->tenants()->get('org-1'));

        $this->assertTrue($this->circuitBreaker->isOpen());

        try {
            $client->tenants()->get('org-1');
            $this->fail('No exception');
        } catch (CircuitOpenException) {
            $this->assertCount(3, $this->history, 'An open circuit sends nothing');
        }

        $this->circuitBreaker->recordSuccess();
        $this->assertFalse($this->circuitBreaker->isOpen());
    }

    public function testExportReturnsFile(): void
    {
        $client = $this->client();
        $this->mock->append(new \GuzzleHttp\Psr7\Response(200, [], 'PK-xlsx'));

        $this->assertSame('PK-xlsx', $client->forTenant('org-1')->finance()->export([new ExternalRef('stage', '1')]));
        $this->assertStringEndsWith('format=xlsx', $this->sentRequest()->getUri()->getQuery());
    }

    private function assertFailsUnavailable(callable $call): void
    {
        try {
            $call();
            $this->fail('No exception');
        } catch (ServiceUnavailableException $e) {
            $this->assertNotInstanceOf(CircuitOpenException::class, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryData(): array
    {
        $totals = ['plan' => '10000.00', 'fact' => '6000.00', 'deviation' => '-4000.00'];

        return [
            'currency'      => 'RUB',
            'external_refs' => [['type' => 'stage', 'id' => '1', 'label' => null]],
            'from'          => '2026-10-01',
            'to'            => null,
            'categories'    => [
                [
                    'id'        => 'c1',
                    'code'      => 'entry_fee',
                    'name'      => 'Взносы',
                    'direction' => 'income',
                    'level'     => 'project',
                    'parent_id' => null,
                    'archived'  => false,
                    ...$totals,
                ],
            ],
            'totals'        => [
                'income'  => $totals,
                'expense' => ['plan' => '0.00', 'fact' => '0.00', 'deviation' => '0.00'],
                'result'  => $totals,
            ],
        ];
    }
}
