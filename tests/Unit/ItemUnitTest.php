<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Dto\WarehouseItem;
use Mnd\BackOffice\Dto\WarehouseItemInput;
use Mnd\BackOffice\Enum\ItemUnit;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\WarehouseRequests;
use PHPUnit\Framework\TestCase;

class ItemUnitTest extends TestCase
{
    use MocksHttp;

    private const ITEM = '01a14100-0000-7000-8000-0000000000c1';

    public function testSpellingsAreUnitsOfTheList(): void
    {
        $this->assertSame(ItemUnit::Piece, ItemUnit::fromInput('шт'));
        $this->assertSame(ItemUnit::Kilogram, ItemUnit::fromInput(' Кг. '));
        $this->assertSame(ItemUnit::Bottle, ItemUnit::fromInput('bottle'));
        $this->assertNull(ItemUnit::fromInput('пара'));
        $this->assertSame('г', ItemUnit::Gram->label());
        $this->assertSame(ItemUnit::Kilogram, ItemUnit::Gram->baseUnit());
    }

    public function testConvertsWithinADimensionOnly(): void
    {
        $this->assertSame('0.250', ItemUnit::Gram->convert('250', ItemUnit::Kilogram));
        $this->assertSame('1500.000', ItemUnit::Litre->convert('1.5', ItemUnit::Millilitre));
        $this->assertSame('2.000', ItemUnit::Metre->convert('2', ItemUnit::Metre));
        $this->assertNull(ItemUnit::Gram->convert('0.5', ItemUnit::Kilogram));
        $this->assertNull(ItemUnit::Kilogram->convert('1', ItemUnit::Litre));
        $this->assertNull(ItemUnit::Bottle->convert('1', ItemUnit::Piece));
    }

    public function testPackagedItemIsWrittenAndReadInItsStockUnit(): void
    {
        $input = new WarehouseItemInput('Масло', ItemUnit::Bottle, packageUnit: ItemUnit::Litre, packageSize: '0.75');
        $this->assertSame(['bottle', 'l', '0.75'], [$input->toArray()['unit'], $input->toArray()['package_unit'], $input->toArray()['package_size']]);
        $this->assertSame('шт', (new WarehouseItemInput('Конус', 'шт'))->toArray()['unit']);

        $item = WarehouseItem::fromArray(
            [
                'id' => self::ITEM, 'name' => 'Масло', 'category' => null, 'unit' => 'bottle', 'unit_label' => 'бут',
                'package_unit' => 'l', 'package_size' => '0.750', 'stock_unit' => 'l', 'stock_unit_label' => 'л',
                'sku' => null, 'accounting' => 'quantity', 'min_balance' => null, 'photo_url' => null, 'archived' => false,
            ]
        );
        $this->assertTrue($item->isPackaged());
        $this->assertSame(ItemUnit::Bottle, $item->itemUnit());
        $this->assertSame(ItemUnit::Litre, $item->stockItemUnit());

        // An older service: free text, no package, the stock in the unit itself.
        $old = WarehouseItem::fromArray(['id' => self::ITEM, 'name' => 'Шина', 'unit' => 'шт', 'accounting' => 'quantity', 'archived' => false]);
        $this->assertFalse($old->isPackaged());
        $this->assertSame(ItemUnit::Piece, $old->stockItemUnit());

        $receipt = WarehouseRequests::receipt(self::ITEM, 'loc-1', packages: '4', packageCost: '800');
        $this->assertSame(['4', '800'], [$receipt->body['packages'], $receipt->body['package_cost']]);
        $this->assertArrayNotHasKey('quantity', $receipt->body);
        $this->assertSame('1', WarehouseRequests::issue(self::ITEM, 'loc-1', new ExternalRef('trip', 't'), packages: '1')->body['packages']);
    }

    public function testItemUnitsAreReadFromTheService(): void
    {
        $client = new BackOfficeClient(new Transport($this->mockHttp(), 'https://bo.test', 'bo_key', null, []));
        $unit   = ['code' => 'g', 'label' => 'г', 'dimension' => 'mass', 'base_unit' => 'kg', 'factor' => '0.001', 'packaging' => false, 'package_measure' => false];
        $this->mock->append(self::jsonResponse(200, ['data' => [$unit]]));

        $this->assertSame([$unit], $client->warehouse()->itemUnits());
        $this->assertSame('/v1/warehouse/item-units', $this->sentRequest(0)->getUri()->getPath());
    }
}
