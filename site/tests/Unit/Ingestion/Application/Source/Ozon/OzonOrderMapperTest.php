<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonOrderMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OzonOrderMapperTest extends TestCase
{
    /**
     * Фикстура — обезличенная копия реальной выгрузки 2026-09-01: по одному
     * отправлению на каждый наблюдённый статус.
     */
    public function testMapsEveryPostingFromTheCapturedFixture(): void
    {
        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows());

        self::assertCount(4, $orders);
        self::assertSame(
            ['awaiting_deliver', 'cancelled', 'delivered', 'delivering'],
            $this->sorted(array_map(static fn ($o): string => $o->rawStatus, $orders)),
        );
    }

    /**
     * Естественный ключ — posting_number, а не order_id: в реальной выгрузке
     * 100 отправлений приходились на 89 заказов, и статусами живёт именно
     * отправление.
     */
    public function testExternalIdIsPostingNumberAndOrderNumberIsKeptSeparately(): void
    {
        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows());
        $first = $orders[0];

        self::assertSame('00001-0001-1', $first->externalId);
        self::assertSame('00001-0001', $first->externalOrderId);
    }

    /**
     * substatus сохраняется дословно, но в нормализацию не идёт: в выгрузке
     * posting_on_way_to_city и posting_in_pickup_point оба лежат под delivering.
     */
    public function testSubstatusIsKeptVerbatim(): void
    {
        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows());

        self::assertSame('posting_on_way_to_city', $orders[0]->rawSubstatus);
    }

    public function testSchemeComesFromResourceTypeNotFromPayload(): void
    {
        $mapper = new OzonOrderMapper();

        self::assertSame(IngestOrderScheme::FBO, $mapper->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows())[0]->scheme);
        self::assertSame(IngestOrderScheme::FBS, $mapper->map($this->rawRecord(OzonResourceType::ORDERS_FBS), $this->fixtureRows())[0]->scheme);
    }

    /**
     * Цена приходит строкой рублей и хранится в копейках: денежная арифметика
     * во float — источник расхождений.
     */
    public function testPriceIsConvertedToMinorUnits(): void
    {
        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows());
        $item = $orders[0]->items[0];

        self::assertSame('209500', $item->priceMinor);
        self::assertSame('RUB', $item->currency);
        self::assertSame(1, $item->quantity);
    }

    /**
     * Краевые случаи конвертации цены. Проверяются отдельно, потому что
     * денежная арифметика — то место, где тихая ошибка дороже всего.
     */
    #[DataProvider('priceCases')]
    public function testPriceConversionEdgeCases(mixed $price, ?string $expected): void
    {
        $rows = [[
            'posting_number' => 'p-1',
            'status' => 'delivering',
            'created_at' => '2026-09-01T10:00:00Z',
            'products' => [['sku' => 1, 'quantity' => 1, 'price' => $price, 'currency_code' => 'RUB']],
        ]];

        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $rows);

        self::assertSame($expected, $orders[0]->items[0]->priceMinor);
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function priceCases(): iterable
    {
        yield 'целые рубли' => ['2095.00', '209500'];
        yield 'копейки' => ['0.50', '50'];
        yield 'ноль' => ['0.00', '0'];
        yield 'без дробной части' => ['1490', '149000'];
        yield 'одна цифра после точки' => ['12.3', '1230'];
        yield 'не число' => ['бесплатно', null];
        yield 'null' => [null, null];
    }

    /**
     * lineNo — часть ключа идемпотентности, поэтому он индекс позиции в
     * исходном массиве, а не что-то производное от содержимого.
     */
    public function testLineNumbersFollowTheSourceArrayOrder(): void
    {
        $rows = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 5).'/Fixtures/Marketplace/Orders/ozon_posting_fbo_multi_item.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        )['result'];

        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $rows);

        self::assertCount(2, $orders[0]->items);
        self::assertSame([0, 1], array_map(static fn ($i): int => $i->lineNo, $orders[0]->items));
    }

    /**
     * Данные для резолва листинга должны попасть в sourceData: OzonListingResolver
     * читает именно offer_id и sku.
     */
    public function testItemCarriesResolverInputInSourceData(): void
    {
        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows());
        $sourceData = $orders[0]->items[0]->sourceData;

        self::assertSame('TEST-ART-001-1', $sourceData['offer_id']);
        self::assertSame('700000011', $sourceData['sku']);
    }

    /**
     * Пустой ответ FBS — наблюдённая реальность, а не ошибка: в окне выгрузки
     * не было ни одного FBS-отправления.
     */
    public function testEmptyPayloadYieldsNoOrders(): void
    {
        self::assertSame([], (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBS), []));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureRows(): array
    {
        $path = \dirname(__DIR__, 5).'/Fixtures/Marketplace/Orders/ozon_posting_fbo_list.json';

        return json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR)['result'];
    }

    private function rawRecord(string $resourceType): IngestRawRecord
    {
        return new IngestRawRecord(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-main',
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: 'window-1',
            storagePath: 'path',
            hash: str_repeat('a', 64),
            byteSize: 10,
            fetchedAt: new \DateTimeImmutable('2026-09-01 12:00:00'),
            syncJobId: Uuid::uuid7()->toString(),
        );
    }
}
