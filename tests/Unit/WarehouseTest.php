<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\ExternalRef;
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

        $history = $warehouse->unitHistory('u1');
        $this->assertSame('in_repair', $history->unit->condition);
        $this->assertSame('Не включается', $history->conditions[0]['comment']);
    }
}
