<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Exceptions\ApiErrorException;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\WarehouseRequests;
use PHPUnit\Framework\TestCase;

class WarehouseTest extends TestCase
{
    use MocksHttp;

    private const ITEM = '01a14100-0000-7000-8000-0000000000c1';

    private function client(): BackOfficeClient
    {
        return new BackOfficeClient(new Transport($this->mockHttp(), 'https://bo.test', 'bo_key', null, []));
    }

    public function testItemsByIdsAskOnceForEachChunkOfTwoHundred(): void
    {
        $client = $this->client();
        $ids    = array_map(fn (int $i) => sprintf('01a14100-0000-7000-8000-%012d', $i), range(1, 250));
        $item   = fn (string $id): array => ['id' => $id, 'name' => 'Шина ' . $id, 'category' => null, 'unit' => 'шт', 'sku' => null, 'accounting' => 'serial', 'min_balance' => null, 'photo_url' => null, 'archived' => true];
        $this->mock->append(
            self::jsonResponse(200, ['data' => [$item($ids[0])], 'meta' => ['next_cursor' => null]]),
            self::jsonResponse(200, ['data' => [$item($ids[249])], 'meta' => ['next_cursor' => null]]),
        );

        $found = $client->warehouse()->itemsByIds([...$ids, $ids[0]]);

        $this->assertSame([$ids[0], $ids[249]], array_keys($found));
        $this->assertTrue($found[$ids[249]]->archived);
        $this->assertCount(2, $this->history);
        parse_str($this->sentRequest(0)->getUri()->getQuery(), $first);
        parse_str($this->sentRequest(1)->getUri()->getQuery(), $second);
        $this->assertCount(200, $first['ids']);
        $this->assertCount(50, $second['ids']);
        $this->assertSame('200', $first['per_page']);
        $this->assertStringContainsString('ids%5B%5D=', $this->sentRequest(0)->getUri()->getQuery());

        $this->assertSame([], $client->warehouse()->itemsByIds([]));
        $this->assertCount(2, $this->history);
        $this->expectException(\InvalidArgumentException::class);
        WarehouseRequests::itemsByIds($ids);
    }

    public function testMovementsOfOneItemShareStreamAndKey(): void
    {
        $issue   = WarehouseRequests::issue(self::ITEM, 'loc-1', new ExternalRef('stage_staff', '17', 'Петров'), '2', dueAt: '2026-11-10T18:00:00+03:00');
        $back    = WarehouseRequests::returnIssue(self::ITEM, 'issue-1');
        $receipt = WarehouseRequests::receipt(self::ITEM, 'loc-1', '5', '100.50', sourceType: 'invoice', sourceId: 'INV-9');

        $this->assertSame('warehouse_item:' . self::ITEM, $issue->stream);
        $this->assertSame($issue->stream, $back->stream);
        $this->assertSame($issue->stream, $receipt->stream);
        $this->assertNotNull($issue->idempotencyKey);
        $this->assertSame(
            [
                'type'             => 'issue',
                'item_id'          => self::ITEM,
                'from_location_id' => 'loc-1',
                'recipient'        => ['type' => 'stage_staff', 'id' => '17', 'label' => 'Петров'],
                'quantity'         => '2',
                'due_at'           => '2026-11-10T18:00:00+03:00',
            ],
            $issue->body
        );
        $this->assertSame(['type' => 'return', 'item_id' => self::ITEM, 'issue_id' => 'issue-1'], $back->body);
        $this->assertSame('INV-9', $receipt->body['source_id']);
    }

    public function testReturnBySourceFollowsTheIssueInItsStream(): void
    {
        $issue = WarehouseRequests::issue(self::ITEM, 'loc-1', new ExternalRef('stage_staff', '17'), '2', sourceType: 'stage_staff_issue', sourceId: 'i-1');
        $back  = WarehouseRequests::returnBySource(self::ITEM, 'stage_staff_issue', 'i-1', sourceType: 'stage_staff_return', sourceId: 'r-1');

        $this->assertSame($issue->stream, $back->stream);
        $this->assertSame(
            [
                'type'              => 'return',
                'item_id'           => self::ITEM,
                'issue_source_type' => 'stage_staff_issue',
                'issue_source_id'   => 'i-1',
                'source_type'       => 'stage_staff_return',
                'source_id'         => 'r-1',
            ],
            $back->body
        );
        $this->assertTrue((new ApiErrorException(409, 'issue_not_found', 'x'))->isRetriable());
    }

    public function testConsumptionSettlesTheIssueInItsStream(): void
    {
        $issue    = WarehouseRequests::issue(self::ITEM, 'loc-1', new ExternalRef('trip', 't-1'), '10', sourceType: 'trip_issue', sourceId: 'i-1');
        $byId     = WarehouseRequests::consumeIssue(self::ITEM, 'issue-1', '6');
        $bySource = WarehouseRequests::consumeBySource(
            self::ITEM,
            'trip_issue',
            'i-1',
            sourceType: 'trip_consumption',
            sourceId: 'c-1',
            externalRef: new ExternalRef('trip', 't-1')
        );

        $this->assertSame($issue->stream, $byId->stream);
        $this->assertSame($issue->stream, $bySource->stream);
        $this->assertNotNull($bySource->idempotencyKey);
        $this->assertSame(['type' => 'consume', 'item_id' => self::ITEM, 'issue_id' => 'issue-1', 'quantity' => '6'], $byId->body);
        $this->assertSame(
            [
                'type'              => 'consume',
                'item_id'           => self::ITEM,
                'issue_source_type' => 'trip_issue',
                'issue_source_id'   => 'i-1',
                'source_type'       => 'trip_consumption',
                'source_id'         => 'c-1',
                'external_ref'      => ['type' => 'trip', 'id' => 't-1', 'label' => null],
            ],
            $bySource->body
        );
        $this->assertFalse((new ApiErrorException(409, 'consume_exceeds_issue', 'x'))->isRetriable());
    }

    public function testCorrectionsCarryTheirOwnFields(): void
    {
        $this->assertSame(
            ['type' => 'inventory', 'item_id' => self::ITEM, 'location_id' => 'loc-1', 'actual_quantity' => '7'],
            WarehouseRequests::inventory(self::ITEM, 'loc-1', '7')->body
        );
        $this->assertSame('-1.5', WarehouseRequests::adjustment(self::ITEM, 'loc-1', '-1.5')->body['delta']);
        $this->assertSame('TX-1', WarehouseRequests::receipt(self::ITEM, 'loc-1', serialNumber: 'TX-1')->body['serial_number']);
    }

    public function testMoveParsesMovementWithBalancesAndEmptyInventory(): void
    {
        $client = $this->client();
        $this->mock->append(
            self::jsonResponse(
                201,
                ['data' => [
                'id'               => 'm1',
                'type'             => 'transfer',
                'item_id'          => self::ITEM,
                'unit_id'          => null,
                'quantity'         => '3.000',
                'from_location_id' => 'loc-1',
                'to_location_id'   => 'loc-2',
                'issue_id'         => null,
                'unit_cost'        => '50.0000',
                'total_cost'       => '150.00',
                'source_type'      => null,
                'source_id'        => null,
                'external_ref'     => ['type' => 'stage', 'id' => '3', 'label' => null],
                'comment'          => null,
                'actor'            => ['id' => '1', 'name' => 'Кладовщик'],
                'occurred_at'      => '2026-10-18T10:00:00+00:00',
                'balances'         => [['item_id' => self::ITEM, 'location_id' => 'loc-2', 'quantity' => '3.000', 'avg_cost' => '50.0000']],
                ]]
            ),
            self::jsonResponse(200, ['data' => null])
        );
        $warehouse = $client->forTenant('org-1')->actingAs(new Actor('1', 'Кладовщик'))->warehouse();

        $movement = $warehouse->move(WarehouseRequests::transfer(self::ITEM, 'loc-1', 'loc-2', '3', externalRef: new ExternalRef('stage', '3')));

        $this->assertSame('150.00', $movement?->totalCost);
        $this->assertSame('3.000', $movement?->balances[0]->quantity);
        $this->assertSame('stage', $movement?->externalRef?->type);
        $this->assertSame('org-1', $this->sentRequest()->getHeaderLine('X-Tenant'));
        $this->assertNull($warehouse->move(WarehouseRequests::inventory(self::ITEM, 'loc-1', '3')));
    }

    public function testIssuesQueryAndUnitHistory(): void
    {
        $client = $this->client();
        $this->mock->append(
            self::jsonResponse(
                200,
                ['data' => [[
                'id'                => 'i1',
                'item_id'           => self::ITEM,
                'unit_id'           => null,
                'location_id'       => 'loc-1',
                'movement_id'       => 'm1',
                'recipient'         => ['type' => 'stage_staff', 'id' => '17', 'label' => 'Петров'],
                'quantity'          => '2.000',
                'returned_quantity' => '0.000',
                'consumed_quantity' => '0.500',
                'due_at'            => '2026-11-02T15:00:00+00:00',
                'closed_at'         => null,
                'overdue'           => true,
                ]]]
            ),
            self::jsonResponse(
                200,
                ['data' => [
                'unit'       => ['id' => 'u1', 'item_id' => self::ITEM, 'serial_number' => 'CAM-1', 'condition' => 'in_repair', 'location_id' => 'loc-1', 'issue_id' => null],
                'movements'  => [],
                'conditions' => [['from' => 'ok', 'to' => 'in_repair', 'comment' => 'Не включается', 'actor' => ['id' => '1', 'name' => 'К'], 'changed_at' => '2026-10-18T10:00:00+00:00']],
                ]]
            )
        );
        $warehouse = $client->forTenant('org-1')->warehouse();

        $issues = $warehouse->issues(overdueOnly: true, recipient: new ExternalRef('stage_staff', '17'));
        $this->assertSame('overdue=1&recipient=stage_staff%3A17', $this->sentRequest()->getUri()->getQuery());
        $this->assertTrue($issues[0]->overdue && $issues[0]->isOpen());
        $this->assertSame('Петров', $issues[0]->recipient->label);
        $this->assertSame('0.500', $issues[0]->consumedQuantity);

        $history = $warehouse->unitHistory('u1');
        $this->assertSame('in_repair', $history->unit->condition);
        $this->assertSame('Не включается', $history->conditions[0]['comment']);
    }

    public function testLabelsAreIssuedResolvedAssignedAndRetired(): void
    {
        $warehouse = $this->client()->forTenant('org-1')->warehouse();
        $label     = ['id' => 'l1', 'code' => '7K2QX9M4H1PZB3TR', 'short_code' => 'Q-000001', 'batch_id' => 'b1', 'status' => 'available', 'unit_id' => null];
        $unit      = ['id' => 'u1', 'item_id' => self::ITEM, 'serial_number' => 'T-1', 'condition' => 'ok', 'location_id' => 'loc-1', 'issue_id' => null];
        $assigned  = ['status' => 'assigned', 'unit_id' => 'u1', 'assigned_at' => '2026-11-01T10:00:00+00:00'] + $label;
        $this->mock->append(
            self::jsonResponse(201, ['data' => ['batch_id' => 'b1', 'labels' => [$label]]]),
            self::jsonResponse(200, ['data' => [...$assigned, 'unit' => [...$unit, 'label' => ['code' => $label['code'], 'short_code' => 'Q-000001']]]]),
            self::jsonResponse(200, ['data' => $assigned]),
            self::jsonResponse(200, ['data' => ['status' => 'retired', 'retire_reason' => 'lost', 'retired_at' => '2026-11-02T10:00:00+00:00'] + $assigned])
        );

        [$batch, $labels] = $warehouse->issueLabels(1);
        $this->assertSame(['b1', 'Q-000001', true], [$batch, $labels[0]->shortCode, $labels[0]->isAvailable()]);
        $this->assertSame('{"count":1}', (string) $this->sentRequest()->getBody());
        $this->assertNotSame('', $this->sentRequest()->getHeaderLine('Idempotency-Key'));

        $scanned = $warehouse->resolveLabel('Q-1');
        $this->assertSame('/v1/warehouse/labels/Q-1', $this->sentRequest(1)->getUri()->getPath());
        $this->assertSame(['u1', 'Q-000001'], [$scanned->unit?->id, $scanned->unit?->labelShortCode]);

        $this->assertSame('assigned', $warehouse->assignLabel($label['code'], 'u1')->status);
        $this->assertSame('/v1/warehouse/labels/7K2QX9M4H1PZB3TR/assign', $this->sentRequest(2)->getUri()->getPath());
        $this->assertSame('{"unit_id":"u1"}', (string) $this->sentRequest(2)->getBody());

        $retired = $warehouse->retireLabel($label['code'], 'lost');
        $this->assertSame(['retired', 'lost', 'u1'], [$retired->status, $retired->retireReason, $retired->unitId]);
    }

    public function testLabelAssignmentGoesInTheStreamOfTheUnit(): void
    {
        $request = WarehouseRequests::assignLabel('7K2QX9M4H1PZB3TR', 'u1');

        $this->assertSame('warehouse_unit:u1', $request->stream);
        $this->assertNull($request->idempotencyKey);
    }

    public function testListsGoPageByPage(): void
    {
        $warehouse = $this->client()->forTenant('org-1')->warehouse();
        $unit      = ['id' => 'u1', 'item_id' => self::ITEM, 'serial_number' => 'T-1', 'condition' => 'ok', 'location_id' => 'loc-1', 'issue_id' => null];
        $this->mock->append(
            self::jsonResponse(200, ['data' => [$unit], 'meta' => ['next_cursor' => 'c2']]),
            self::jsonResponse(200, ['data' => [], 'meta' => ['next_cursor' => null]]),
            self::jsonResponse(200, ['data' => [], 'meta' => ['next_cursor' => null]]),
            // A service before pages sends no meta.
            self::jsonResponse(200, ['data' => [$unit]])
        );

        [$units, $next] = $warehouse->unitsPage(itemId: self::ITEM, perPage: 100);
        $this->assertSame('item_id=' . self::ITEM . '&per_page=100', $this->sentRequest()->getUri()->getQuery());
        $this->assertSame('T-1', $units[0]->serialNumber);
        $this->assertSame('c2', $next);

        [$issues, $next] = $warehouse->issuesPage(openOnly: true, perPage: 50, cursor: 'c2');
        $this->assertSame('open=1&per_page=50&cursor=c2', $this->sentRequest(1)->getUri()->getQuery());
        $this->assertSame([[], null], [$issues, $next]);

        [, $next] = $warehouse->reservationsPage(activeOnly: true, perPage: 10);
        $this->assertSame('active=1&per_page=10', $this->sentRequest(2)->getUri()->getQuery());
        $this->assertNull($next);

        $this->assertCount(1, $warehouse->units());
    }

    public function testReservationAndItsIssueAndCancelShareTheStreamOfTheItem(): void
    {
        $reserve = WarehouseRequests::reserve(self::ITEM, 'loc-1', new ExternalRef('stage', '3', 'Этап 3'), '4', expiresAt: '2026-11-03T20:00:00+05:00', sourceType: 'stage_item', sourceId: 's-1');
        $issue   = WarehouseRequests::issueReservedBySource(self::ITEM, 'stage_item', 's-1', new ExternalRef('stage_staff', '17'), quantity: '1');
        $byId    = WarehouseRequests::issueReserved(self::ITEM, 'res-1', new ExternalRef('stage_staff', '17'));
        $cancel  = WarehouseRequests::cancelReservationBySource(self::ITEM, 'stage_item', 's-1');

        $this->assertSame('warehouse_item:' . self::ITEM, $reserve->stream);
        $this->assertSame($reserve->stream, $issue->stream);
        $this->assertSame($reserve->stream, $cancel->stream);
        $this->assertNotNull($reserve->idempotencyKey);
        $this->assertSame(
            [
                'item_id'     => self::ITEM,
                'location_id' => 'loc-1',
                'holder'      => ['type' => 'stage', 'id' => '3', 'label' => 'Этап 3'],
                'quantity'    => '4',
                'expires_at'  => '2026-11-03T20:00:00+05:00',
                'source_type' => 'stage_item',
                'source_id'   => 's-1',
            ],
            $reserve->body
        );
        $this->assertSame(
            [
                'type'                    => 'issue',
                'item_id'                 => self::ITEM,
                'reservation_source_type' => 'stage_item',
                'reservation_source_id'   => 's-1',
                'recipient'               => ['type' => 'stage_staff', 'id' => '17', 'label' => null],
                'quantity'                => '1',
            ],
            $issue->body
        );
        $this->assertSame('res-1', $byId->body['reservation_id']);
        $this->assertArrayNotHasKey('from_location_id', $byId->body);
        $this->assertSame(['POST', '/v1/warehouse/reservations/cancel'], [$cancel->method, $cancel->path]);
        $this->assertSame(['source_type' => 'stage_item', 'source_id' => 's-1'], $cancel->body);
        $this->assertSame('/v1/warehouse/reservations/res-1/cancel', WarehouseRequests::cancelReservation(self::ITEM, 'res-1')->path);
    }

    public function testOnlyAConflictOfAReservationNotThereYetIsRetried(): void
    {
        $this->assertTrue((new ApiErrorException(409, 'reservation_not_found', 'x'))->isRetriable());
        $this->assertFalse((new ApiErrorException(404, 'reservation_not_found', 'x'))->isRetriable());
        $this->assertFalse((new ApiErrorException(409, 'reservation_closed', 'x'))->isRetriable());
    }

    public function testReservationsAndBalancesParseWhatIsHeld(): void
    {
        $client      = $this->client();
        $reservation = [
            'id'              => 'res-1',
            'item_id'         => self::ITEM,
            'unit_id'         => null,
            'location_id'     => 'loc-1',
            'holder'          => ['type' => 'stage', 'id' => '3', 'label' => 'Этап 3'],
            'quantity'        => '4.000',
            'issued_quantity' => '1.000',
            'remaining'       => '3.000',
            'status'          => 'active',
            'expires_at'      => null,
            'source_type'     => 'stage_item',
            'source_id'       => 's-1',
            'comment'         => null,
            'actor'           => ['id' => '1', 'name' => 'Кладовщик'],
            'created_at'      => '2026-10-20T10:00:00+00:00',
            'closed_at'       => null,
        ];
        $this->mock->append(
            self::jsonResponse(200, ['data' => [$reservation]]),
            self::jsonResponse(200, ['data' => ['status' => 'cancelled', 'remaining' => '0.000', 'closed_at' => '2026-10-20T11:00:00+00:00'] + $reservation]),
            self::jsonResponse(200, ['data' => [['item_id' => self::ITEM, 'location_id' => 'loc-1', 'quantity' => '10.000', 'avg_cost' => '5.0000', 'reserved' => '3.000', 'available' => '7.000']]])
        );
        $warehouse = $client->forTenant('org-1')->warehouse();

        $list = $warehouse->reservations(activeOnly: true, holder: new ExternalRef('stage', '3'));
        $this->assertSame('active=1&holder=stage%3A3', $this->sentRequest()->getUri()->getQuery());
        $this->assertTrue($list[0]->isActive());
        $this->assertSame('3.000', $list[0]->remaining);
        $this->assertSame('Этап 3', $list[0]->holder->label);

        $cancelled = $warehouse->cancelReservation(self::ITEM, 'res-1');
        $this->assertFalse($cancelled->isActive());
        $this->assertSame('/v1/warehouse/reservations/res-1/cancel', $this->sentRequest(1)->getUri()->getPath());

        $balance = $warehouse->balances(self::ITEM)[0];
        $this->assertSame(['3.000', '7.000'], [$balance->reserved, $balance->available]);
    }
}
