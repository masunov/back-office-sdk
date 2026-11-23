<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Dto\WarehouseIssue;
use Mnd\BackOffice\Dto\WarehouseItemInput;
use Mnd\BackOffice\Dto\WarehouseMovement;
use Mnd\BackOffice\Enum\ItemUnit;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\WarehouseRequests;
use PHPUnit\Framework\TestCase;

class LotTest extends TestCase
{
    use MocksHttp;

    private const ITEM = '01a14100-0000-7000-8000-0000000000c1';
    private const LOT  = '01a14100-0000-7000-8000-0000000000d1';

    public function testMovementsNameTheirLots(): void
    {
        $receipt = WarehouseRequests::receipt(self::ITEM, 'loc-1', '2', lotNumber: 'B-1', expiresOn: '2026-11-20');
        $this->assertSame(['B-1', '2026-11-20'], [$receipt->body['lot_number'], $receipt->body['expires_on']]);
        $this->assertArrayNotHasKey('lot_id', $receipt->body);

        $this->assertSame(self::LOT, WarehouseRequests::writeOff(self::ITEM, 'loc-1', '1', lotId: self::LOT)->body['lot_id']);
        $this->assertSame(self::LOT, WarehouseRequests::returnIssue(self::ITEM, 'issue-1', lotId: self::LOT)->body['lot_id']);
        $this->assertSame(
            ['location_id' => 'loc-1', 'actual_quantity' => '1.5', 'lot_id' => self::LOT],
            array_intersect_key(
                WarehouseRequests::inventory(self::ITEM, 'loc-1', '1.5', lotId: self::LOT)->body,
                array_flip(['location_id', 'actual_quantity', 'lot_id'])
            )
        );
        $this->assertArrayNotHasKey('lot_id', WarehouseRequests::issue(self::ITEM, 'loc-1', new ExternalRef('trip', 't'), '1')->body);

        $this->assertSame(5, (new WarehouseItemInput('Хлеб', ItemUnit::Piece, shelfLifeDays: 5))->toArray()['shelf_life_days']);
        $this->assertSame(2, WarehouseRequests::putSettings(null, null, 2)->body['expiry_warning_days']);
        $this->assertArrayNotHasKey('expiry_warning_days', WarehouseRequests::putSettings(null, null)->body);
    }

    public function testDirectConsumptionNamesOnlyItsPlaceAndLot(): void
    {
        $request = WarehouseRequests::consumeFromLocation(self::ITEM, 'fridge', '0.250', sourceType: 'food', sourceId: '1', lotId: self::LOT);
        $this->assertSame('consume', $request->body['type']);
        $this->assertSame('fridge', $request->body['from_location_id']);
        $this->assertSame('0.250', $request->body['quantity']);
        $this->assertSame(self::LOT, $request->body['lot_id']);
        $this->assertArrayNotHasKey('issue_id', $request->body);
        $this->assertArrayNotHasKey('to_location_id', $request->body);
        $this->assertSame('2', WarehouseRequests::consumeFromLocation(self::ITEM, 'fridge', packages: '2')->body['packages']);
    }

    public function testMovementAndIssueCarryTheirLots(): void
    {
        $part     = ['lot_id' => self::LOT, 'number' => 'B-1', 'expires_on' => '2026-11-20', 'quantity' => '0.750'];
        $movement = WarehouseMovement::fromArray(
            [
                'id' => 'm-1', 'type' => 'issue', 'item_id' => self::ITEM, 'quantity' => '0.750', 'actor' => [],
                'occurred_at' => '2026-11-15T09:00:00+00:00', 'lots' => [$part],
            ]
        );
        $this->assertSame('B-1', $movement->lots[0]->number);
        $this->assertNull($movement->lots[0]->settledQuantity);

        $issue = WarehouseIssue::fromArray(
            [
                'id' => 'i-1', 'item_id' => self::ITEM, 'location_id' => 'loc-1', 'movement_id' => 'm-1',
                'recipient' => ['type' => 'trip', 'id' => 't'], 'quantity' => '0.750', 'returned_quantity' => '0.000',
                'overdue' => false, 'lots' => [[...$part, 'settled_quantity' => '0.250']],
            ]
        );
        $this->assertSame('0.250', $issue->lots[0]->settledQuantity);

        // An older service: no lots.
        $old = WarehouseMovement::fromArray(
            ['id' => 'm-2', 'type' => 'issue', 'item_id' => self::ITEM, 'quantity' => '1', 'actor' => [], 'occurred_at' => '2026-11-15T09:00:00+00:00']
        );
        $this->assertSame([], $old->lots);
    }

    public function testLotsArePagedByExpiry(): void
    {
        $client = new BackOfficeClient(new Transport($this->mockHttp(), 'https://bo.test', 'bo_key', null, []));
        $lot    = [
            'id' => self::LOT, 'item_id' => self::ITEM, 'number' => 'B-1', 'expires_on' => '2026-11-14', 'expired' => true,
            'quantity' => '1.000', 'balances' => [['location_id' => 'loc-1', 'quantity' => '1.000']],
        ];
        $this->mock->append(
            self::jsonResponse(200, ['data' => [$lot], 'meta' => ['next_cursor' => 'c2']]),
            self::jsonResponse(200, ['data' => $lot]),
        );

        [$lots, $next] = $client->warehouse()->lotsPage(itemId: self::ITEM, expiredOnly: true, perPage: 50);
        $this->assertTrue($lots[0]->expired);
        $this->assertSame([['locationId' => 'loc-1', 'quantity' => '1.000']], $lots[0]->balances);
        $this->assertSame('c2', $next);
        parse_str($this->sentRequest(0)->getUri()->getQuery(), $query);
        $this->assertSame(['item_id' => self::ITEM, 'expired' => '1', 'per_page' => '50'], $query);

        $this->assertSame('B-1', $client->warehouse()->lot(self::LOT)->number);
        $this->assertSame('/v1/warehouse/lots/' . self::LOT, $this->sentRequest(1)->getUri()->getPath());
    }
}
