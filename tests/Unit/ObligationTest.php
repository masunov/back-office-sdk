<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Exceptions\ApiErrorException;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\FinanceRequests;
use PHPUnit\Framework\TestCase;

class ObligationTest extends TestCase
{
    use MocksHttp;

    private const OBLIGATION = [
        'id'               => '01a14100-0000-7000-8000-0000000000d1',
        'category'         => 'hotel',
        'direction'        => 'expense',
        'counterparty'     => ['type' => 'contractor', 'id' => '7', 'label' => 'Гостиница'],
        'external_ref'     => ['type' => 'trip', 'id' => '42', 'label' => null],
        'amount'           => '24000.00',
        'agreed_on'        => '2026-11-01',
        'status'           => 'agreed',
        'fulfilled_amount' => null,
        'payment_status'   => 'unpaid',
        'overdue'          => false,
        'source_type'      => 'trip_obligation',
        'source_id'        => 'ob-1',
        'totals'           => ['agreed' => '24000.00', 'paid' => '0.00', 'remaining' => '24000.00', 'due' => '0.00'],
        'schedule'         => [
            ['key' => 'prepay', 'amount' => '12000.00', 'due_on' => '2026-11-05', 'label' => 'Предоплата', 'paid' => '0.00', 'remaining' => '12000.00', 'overdue' => false, 'external_ref' => ['type' => 'stage', 'id' => '3', 'label' => null]],
            ['key' => 'all', 'amount' => '12000.00', 'due_on' => null, 'label' => null, 'paid' => '0.00', 'remaining' => '12000.00', 'overdue' => false],
        ],
        'comment'          => null,
        'documents'        => [],
    ];

    private const RECOGNITION = [
        'source_type'  => 'trip_cost_entry',
        'source_id'    => 'ce-1',
        'operation_id' => null,
        'amount'       => '0.00',
        'linked_at'    => '2026-11-01T12:00:00+00:00',
    ];

    private function client(): BackOfficeClient
    {
        return new BackOfficeClient(new Transport($this->mockHttp(), 'https://bo.test', 'bo_key', null, []));
    }

    public function testChangesByTheSourceGoInTheStreamOfTheCreation(): void
    {
        $create   = FinanceRequests::createObligation('hotel', new ExternalRef('contractor', '7'), '24000', 'trip_obligation', 'ob-1');
        $cancel   = FinanceRequests::updateObligation('trip_obligation:ob-1', 'cancelled');
        $schedule = FinanceRequests::putObligationSchedule('trip_obligation:ob-1', [['key' => 'all', 'amount' => '24000']], 'Один платёж');
        $byId     = FinanceRequests::updateObligation(self::OBLIGATION['id'], 'fulfilled');

        $this->assertSame('source:trip_obligation:ob-1', $create->stream);
        $this->assertSame($create->stream, $cancel->stream);
        $this->assertSame($create->stream, $schedule->stream);
        $this->assertNull($byId->stream);
        $this->assertNotNull($create->idempotencyKey);
        $this->assertSame('PATCH', $cancel->method);
        $this->assertSame('/v1/finance/obligations/trip_obligation%3Aob-1', $cancel->path);
        $this->assertSame(['status' => 'cancelled'], $cancel->body);
        // No schedule — the service makes one undated line; nothing empty is sent.
        $this->assertArrayNotHasKey('schedule', $create->body);
        $this->assertSame('agreed', $create->body['status']);
    }

    public function testOperationRecognizesItsObligation(): void
    {
        $operation = FinanceRequests::createOperation(
            'hotel',
            '10000.00',
            '2026-11-01',
            'trip_cost_entry',
            'ce-1',
            obligation: 'trip_obligation:ob-1'
        );
        $this->assertSame('trip_obligation:ob-1', $operation->body['obligation']);
        // The operation goes in its own stream; the obligation not there yet is retried.
        $this->assertSame(FinanceRequests::createOperation('hotel', '1', '2026-11-01', 'trip_cost_entry', 'ce-1')->stream, $operation->stream);
        $this->assertArrayNotHasKey('obligation', FinanceRequests::createOperation('hotel', '1', '2026-11-01')->body);

        $link = FinanceRequests::linkRecognition('trip_obligation:ob-1', 'trip_cost_entry', 'ce-1');
        $this->assertSame('/v1/finance/obligations/trip_obligation%3Aob-1/recognitions', $link->path);
        $this->assertSame(['source_type' => 'trip_cost_entry', 'source_id' => 'ce-1'], $link->body);
        $this->assertNotNull($link->idempotencyKey);
        // After the creation of the obligation, in its stream.
        $this->assertSame(FinanceRequests::putObligationSchedule('trip_obligation:ob-1', [], 'x')->stream, $link->stream);

        $finance = $this->client()->forTenant('org-1')->finance();
        $this->mock->append(
            self::jsonResponse(201, ['data' => self::OBLIGATION + ['recognitions' => [self::RECOGNITION]]])
        );
        $this->assertSame('ce-1', $finance->linkRecognition('trip_obligation:ob-1', 'trip_cost_entry', 'ce-1')->recognitions[0]['source_id']);
    }

    public function testObligationNotThereYetIsRetriedAndAnUnknownIdIsNot(): void
    {
        $this->assertTrue((new ApiErrorException(409, 'obligation_not_found', 'x'))->isRetriable());
        $this->assertFalse((new ApiErrorException(404, 'obligation_not_found', 'x'))->isRetriable());
    }

    public function testObligationsAreReadAndPaged(): void
    {
        $finance = $this->client()->forTenant('org-1')->finance();
        $this->mock->append(
            self::jsonResponse(200, ['data' => self::OBLIGATION]),
            self::jsonResponse(200, ['data' => [self::OBLIGATION], 'meta' => ['next_cursor' => 'c2']]),
            self::jsonResponse(200, ['data' => [['key' => 'prepay', 'change' => 'due_on', 'from' => '2026-11-05', 'to' => '2026-11-08']]])
        );

        $obligation = $finance->obligation('trip_obligation:ob-1');
        $this->assertSame('/v1/finance/obligations/trip_obligation%3Aob-1', $this->sentRequest()->getUri()->getPath());
        $this->assertSame(['expense', '24000.00', 'trip_obligation:ob-1'], [$obligation->direction, $obligation->remaining(), $obligation->sourceKey()]);
        $this->assertSame('42', $obligation->externalRef?->id);
        $this->assertSame(['2026-11-05', null], [$obligation->schedule[0]->dueOn, $obligation->schedule[1]->dueOn]);
        $this->assertSame(['stage:3', null], [$obligation->schedule[0]->externalRef?->key(), $obligation->schedule[1]->externalRef]);
        $this->assertSame('0.00', $obligation->due());

        [$page, $next] = $finance->obligations(ref: new ExternalRef('trip', '42'), overdue: true, perPage: 50);
        $this->assertSame('ref=trip%3A42&overdue=1&per_page=50', $this->sentRequest(1)->getUri()->getQuery());
        $this->assertSame(['c2', 1], [$next, \count($page)]);

        $this->assertSame('due_on', $finance->scheduleHistory(self::OBLIGATION['id'])[0]['change']);
    }
}
