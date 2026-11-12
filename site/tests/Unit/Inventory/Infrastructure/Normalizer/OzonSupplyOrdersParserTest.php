<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure\Normalizer;

use App\Inventory\Application\DTO\OzonSupplyItem;
use App\Inventory\Infrastructure\Normalizer\OzonSupplyOrdersParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OzonSupplyOrdersParserTest extends TestCase
{
    /**
     * Две заявки, три поставки: состав идёт по возрастанию bundle_id ("b-1" < "b-2" < "b-3"),
     * а не в порядке деталей; у "b-2" две страницы состава.
     */
    public function testBundleGroupsFollowSortedBundleIds(): void
    {
        $items = (new OzonSupplyOrdersParser())->parse([
            ['order_ids' => [11, 12], 'last_id' => ''],
            ['orders' => [
                ['order_id' => 12, 'state' => 'IN_TRANSIT', 'supplies' => [
                    ['bundle_id' => 'b-3', 'storage_warehouse' => ['warehouse_id' => 900, 'name' => 'Тверь']],
                ]],
                ['order_id' => 11, 'state' => 'READY_TO_SUPPLY', 'supplies' => [
                    ['bundle_id' => 'b-2', 'storage_warehouse' => ['warehouse_id' => 800, 'name' => 'Казань']],
                    ['bundle_id' => 'b-1'],
                ]],
            ]],
            ['items' => [['sku' => 111, 'offer_id' => 'A', 'quantity' => 5]], 'has_next' => false],
            ['items' => [['sku' => 222, 'offer_id' => 'B', 'quantity' => 7]], 'has_next' => true, 'last_id' => 'x'],
            ['items' => [['sku' => 333, 'offer_id' => 'C', 'quantity' => 1]], 'has_next' => false],
            ['items' => [['sku' => 111, 'offer_id' => 'A', 'quantity' => 300]], 'has_next' => false],
        ]);

        self::assertEquals([
            new OzonSupplyItem('111', 'A', '5', '11', 'READY_TO_SUPPLY', null, null),
            new OzonSupplyItem('222', 'B', '7', '11', 'READY_TO_SUPPLY', '800', 'Казань'),
            new OzonSupplyItem('333', 'C', '1', '11', 'READY_TO_SUPPLY', '800', 'Казань'),
            new OzonSupplyItem('111', 'A', '300', '12', 'IN_TRANSIT', '900', 'Тверь'),
        ], $items);
    }

    public function testDraftOrderIsSkippedButKeepsItsBundleGroup(): void
    {
        $items = (new OzonSupplyOrdersParser())->parse([
            ['order_ids' => [1, 2], 'last_id' => ''],
            ['orders' => [
                ['order_id' => 1, 'state' => 'DATA_FILLING', 'supplies' => [['bundle_id' => 'a']]],
                ['order_id' => 2, 'state' => 'IN_TRANSIT', 'supplies' => [['bundle_id' => 'b']]],
            ]],
            ['items' => [['sku' => 1, 'quantity' => 10]], 'has_next' => false],
            ['items' => [['sku' => 2, 'quantity' => 20]], 'has_next' => false],
        ]);

        self::assertCount(1, $items);
        self::assertSame('2', $items[0]->sku);
        self::assertSame('20', $items[0]->quantity);
    }

    public function testEmptySnapshotGivesNoItems(): void
    {
        self::assertSame([], (new OzonSupplyOrdersParser())->parse([['order_ids' => [], 'last_id' => '']]));
    }

    /**
     * @return iterable<string, array{list<mixed>}>
     */
    public static function brokenSnapshots(): iterable
    {
        $orders = ['orders' => [['order_id' => 1, 'state' => 'IN_TRANSIT', 'supplies' => [['bundle_id' => 'a'], ['bundle_id' => 'b']]]]];
        yield 'групп состава меньше поставок' => [[$orders, ['items' => [['sku' => 1, 'quantity' => 1]], 'has_next' => false]]];
        yield 'последняя страница состава с has_next' => [[$orders, ['items' => [], 'has_next' => false], ['items' => [], 'has_next' => true]]];
        yield 'позиция без SKU' => [[$orders, ['items' => [['quantity' => 1]], 'has_next' => false], ['items' => [], 'has_next' => false]]];
        yield 'неизвестная страница' => [[['result' => []]]];
        yield 'заявка без номера' => [[['orders' => [['state' => 'IN_TRANSIT']]]]];
    }

    /**
     * @param list<mixed> $pages
     */
    #[DataProvider('brokenSnapshots')]
    public function testBrokenSnapshotIsRejected(array $pages): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new OzonSupplyOrdersParser())->parse($pages);
    }
}
