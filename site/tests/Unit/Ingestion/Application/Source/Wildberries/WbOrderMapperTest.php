<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\Source\Wildberries\WbOrderMapper;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Domain\Service\IngestOrderStatusMapper;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class WbOrderMapperTest extends TestCase
{
    /**
     * Сшивка двух потоков возможна только если оба приводят заказ к одному
     * externalId. Это главное свойство маппера, поэтому проверяется на
     * реальных фикстурах, а не на выдуманных строках.
     */
    public function testBothFeedsProduceTheSameExternalIdForTheSameOrder(): void
    {
        $mapper = new WbOrderMapper();

        $marketplace = $mapper->map($this->rawRecord(WbResourceType::ORDERS_MARKETPLACE), $this->marketplaceRows())->orders;
        $statistics = $mapper->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), $this->statisticsRows())->orders;

        $shared = array_intersect(
            array_map(static fn ($o): string => $o->externalId, $marketplace),
            array_map(static fn ($o): string => $o->externalId, $statistics),
        );

        self::assertSame(['eTEST.i0000000000000000000000000000001.0.0'], array_values($shared));
    }

    /**
     * Оба потока обязаны дать заказу ОДИН И ТОТ ЖЕ момент времени: statistics
     * отдаёт московское время без зоны, marketplace — UTC с Z.
     */
    public function testBothFeedsAgreeOnOrderedAtForTheSameOrder(): void
    {
        $mapper = new WbOrderMapper();
        $sharedRid = 'eTEST.i0000000000000000000000000000001.0.0';

        $fromMarketplace = $this->orderByExternalId(
            $mapper->map($this->rawRecord(WbResourceType::ORDERS_MARKETPLACE), $this->marketplaceRows())->orders,
            $sharedRid,
        );
        $fromStatistics = $this->orderByExternalId(
            $mapper->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), $this->statisticsRows())->orders,
            $sharedRid,
        );

        self::assertSame(
            $fromMarketplace->orderedAt->format(\DATE_ATOM),
            $fromStatistics->orderedAt->format(\DATE_ATOM),
        );
    }

    public function testMarketplaceRowsAreMappedFromTheCapturedFixture(): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_MARKETPLACE), $this->marketplaceRows());

        self::assertSame([], $batch->skipped);
        self::assertCount(3, $batch->orders);
        self::assertSame(IngestOrderScheme::FBS, $batch->orders[0]->scheme);
        self::assertSame('5000000001', $batch->orders[0]->externalOrderId);
    }

    /**
     * `price` у marketplace-api уже в копейках (в выгрузке 195700 при цене
     * 1957 ₽). Прогон через рублёвую конвертацию завысил бы цену в сто раз.
     */
    public function testMarketplacePriceIsAlreadyInMinorUnits(): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_MARKETPLACE), $this->marketplaceRows());

        self::assertSame('195700', $batch->orders[0]->items[0]->priceMinor);
        self::assertSame('RUB', $batch->orders[0]->items[0]->currency);
    }

    /**
     * `finishedPrice` у statistics-api — в рублях, а не в копейках.
     */
    /**
     * Цену позиции statistics не задаёт: finishedPrice — цена после всех
     * скидок в рублях, а price маркетплейса — цена продажи в копейках. Класть
     * их в одну колонку значило бы менять её смысл в зависимости от того,
     * какой поток заполнил запись. Сумма не теряется — она уходит в атрибуты
     * заказа под собственным именем.
     */
    public function testStatisticsKeepsFinishedPriceInAttributesNotInItemPrice(): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), $this->statisticsRows());

        self::assertNull($batch->orders[0]->items[0]->priceMinor);
        self::assertSame('190000', $batch->orders[0]->attributes['finished_price_minor']);
    }

    /**
     * Валюта не подставляется: поля валюты у statistics-api нет вовсе, а
     * «наверное, рубли» — финансовое утверждение без основания.
     */
    public function testStatisticsDoesNotInventCurrency(): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), $this->statisticsRows());

        self::assertNull($batch->orders[0]->items[0]->currency);
    }

    /**
     * Две оси статуса склеиваются дословно: пара `new / canceled_by_client`
     * одной осью не выражается.
     */
    public function testMarketplaceStatusKeepsBothAxes(): void
    {
        $rows = [[
            'rid' => 'r-1',
            'createdAt' => '2026-08-30T19:18:04Z',
            'nmId' => 1,
            'article' => 'A-1',
            '_ingestion_status' => ['supplierStatus' => 'new', 'wbStatus' => 'canceled_by_client'],
        ]];

        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_MARKETPLACE), $rows);

        self::assertSame('supplierStatus=new;wbStatus=canceled_by_client', $batch->orders[0]->rawStatus);
        self::assertSame(
            IngestOrderStatus::CANCELLED,
            (new IngestOrderStatusMapper())->map(IngestSource::WILDBERRIES, IngestOrderScheme::FBS, $batch->orders[0]->rawStatus),
        );
    }

    /**
     * `/api/v3/orders/status` отдаёт не всё, что отдал `/api/v3/orders`. Заказ
     * при этом существует, поэтому он не теряется: обе оси уходят пустыми,
     * статус деградирует в UNKNOWN и попадает в видимую очередь.
     */
    public function testOrderWithoutStatusDegradesToUnknownInsteadOfDisappearing(): void
    {
        $rows = [['rid' => 'r-1', 'createdAt' => '2026-08-30T19:18:04Z', 'nmId' => 1, 'article' => 'A-1']];

        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_MARKETPLACE), $rows);

        self::assertSame([], $batch->skipped);
        self::assertCount(1, $batch->orders);
        self::assertSame(
            IngestOrderStatus::UNKNOWN,
            (new IngestOrderStatusMapper())->map(IngestSource::WILDBERRIES, IngestOrderScheme::FBS, $batch->orders[0]->rawStatus),
        );
    }

    /**
     * Поток statistics статуса не отдаёт вовсе — только признак отмены.
     */
    public function testStatisticsCancelFlagBecomesCancelledStatus(): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), $this->statisticsRows());
        $cancelled = $this->orderByExternalId($batch->orders, 'eTEST.i9999999999999999999999999999999.0.0');

        self::assertSame('isCancel=true', $cancelled->rawStatus);
        self::assertSame(
            IngestOrderStatus::CANCELLED,
            (new IngestOrderStatusMapper())->map(IngestSource::WILDBERRIES, IngestOrderScheme::FBO, $cancelled->rawStatus),
        );
    }

    /**
     * Нулевая дата WB — заглушка вместо null, а не настоящая дата отмены.
     */
    public function testZeroCancelDateIsNotStoredAsAnAttribute(): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), $this->statisticsRows());
        $notCancelled = $this->orderByExternalId($batch->orders, 'eTEST.i0000000000000000000000000000001.0.0');

        self::assertArrayNotHasKey('cancelled_at', $notCancelled->statusAttributes);
        self::assertFalse($notCancelled->statusAttributes['is_cancel']);
    }

    public function testRealCancelDateIsKept(): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), $this->statisticsRows());
        $cancelled = $this->orderByExternalId($batch->orders, 'eTEST.i9999999999999999999999999999999.0.0');

        self::assertSame('2026-08-29T21:00:00+00:00', $cancelled->statusAttributes['cancelled_at']);
    }

    /**
     * Схема statistics выводится из типа склада строгим словарём. Оба
     * настоящих значения наблюдались в выгрузке; незнакомое или отсутствующее
     * даёт UNKNOWN, а не одну из схем: опечатка или новый тип склада молча
     * меняли бы схему исполнения заказа и всю отчётность по ней.
     */
    #[DataProvider('warehouseSchemeProvider')]
    public function testStatisticsSchemeComesFromWarehouseType(?string $warehouseType, IngestOrderScheme $expected): void
    {
        $rows = [array_filter([
            'srid' => 's-1',
            'date' => '2026-08-30T22:18:04',
            'isCancel' => false,
            'nmId' => 1,
            'warehouseType' => $warehouseType,
        ], static fn (mixed $v): bool => null !== $v)];

        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), $rows);

        self::assertSame($expected, $batch->orders[0]->scheme);
    }

    /**
     * @return iterable<string, array{?string, IngestOrderScheme}>
     */
    public static function warehouseSchemeProvider(): iterable
    {
        yield 'склад продавца' => ['Склад продавца', IngestOrderScheme::FBS];
        yield 'склад WB' => ['Склад WB', IngestOrderScheme::FBO];
        yield 'типа нет' => [null, IngestOrderScheme::UNKNOWN];
        yield 'незнакомый тип' => ['Склад партнёра', IngestOrderScheme::UNKNOWN];
        yield 'опечатка' => ['Cклад продавца', IngestOrderScheme::UNKNOWN];
    }

    /**
     * Строка без ключа сшивки бессмысленна: связать её со вторым потоком
     * нечем, и молчаливый пропуск был бы постоянной потерей.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('skippedRowProvider')]
    public function testMalformedRowsBecomeVisibleSkips(string $resourceType, array $row, string $expectedReason): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord($resourceType), [$row]);

        self::assertSame([], $batch->orders);
        self::assertSame($expectedReason, $batch->skipped[0]['reason']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function skippedRowProvider(): iterable
    {
        yield 'нет rid' => [
            WbResourceType::ORDERS_MARKETPLACE,
            ['createdAt' => '2026-08-30T19:18:04Z', 'nmId' => 1],
            'missing_rid',
        ];
        yield 'нечитаемый createdAt' => [
            WbResourceType::ORDERS_MARKETPLACE,
            ['rid' => 'r-1', 'createdAt' => 'позавчера', 'nmId' => 1],
            'unparsable_created_at',
        ];
        yield 'нет идентификаторов товара' => [
            WbResourceType::ORDERS_MARKETPLACE,
            ['rid' => 'r-1', 'createdAt' => '2026-08-30T19:18:04Z'],
            'missing_product_identity',
        ];
        yield 'неизвестный код валюты' => [
            WbResourceType::ORDERS_MARKETPLACE,
            ['rid' => 'r-1', 'createdAt' => '2026-08-30T19:18:04Z', 'nmId' => 1, 'currencyCode' => 111],
            'unknown_currency_code',
        ];
        // Границы BIGINT: `$digits <= $limit` для numeric-string выполняет
        // ЧИСЛОВОЕ сравнение и у границы приводит операнды к float, где
        // соседние значения перестают различаться.
        yield 'копейки на единицу выше BIGINT' => [
            WbResourceType::ORDERS_MARKETPLACE,
            ['rid' => 'r-1', 'createdAt' => '2026-08-30T19:18:04Z', 'nmId' => 1, 'price' => '9223372036854775808'],
            'malformed_price',
        ];
        yield 'рубли на копейку выше BIGINT' => [
            WbResourceType::ORDERS_STATISTICS,
            ['srid' => 's-1', 'date' => '2026-08-30T22:18:04', 'isCancel' => false, 'nmId' => 1, 'finishedPrice' => '92233720368547758.08'],
            'malformed_price',
        ];
        yield 'больше двух знаков в рублях' => [
            WbResourceType::ORDERS_STATISTICS,
            ['srid' => 's-1', 'date' => '2026-08-30T22:18:04', 'isCancel' => false, 'nmId' => 1, 'finishedPrice' => '10.999'],
            'malformed_price',
        ];
        // Строка длиннее PHP_INT_MAX при приведении к int молча превращалась
        // бы в предельное целое, то есть в другую денежную величину.
        yield 'цена в копейках выше PHP_INT_MAX' => [
            WbResourceType::ORDERS_MARKETPLACE,
            ['rid' => 'r-1', 'createdAt' => '2026-08-30T19:18:04Z', 'nmId' => 1, 'price' => '99999999999999999999999'],
            'malformed_price',
        ];
        yield 'нецелая цена в копейках' => [
            WbResourceType::ORDERS_MARKETPLACE,
            ['rid' => 'r-1', 'createdAt' => '2026-08-30T19:18:04Z', 'nmId' => 1, 'price' => '19.57'],
            'malformed_price',
        ];
        yield 'нет srid' => [
            WbResourceType::ORDERS_STATISTICS,
            ['date' => '2026-08-30T22:18:04', 'isCancel' => false, 'nmId' => 1],
            'missing_srid',
        ];
        yield 'нечитаемая date' => [
            WbResourceType::ORDERS_STATISTICS,
            ['srid' => 's-1', 'date' => 'вчера', 'isCancel' => false, 'nmId' => 1],
            'unparsable_date',
        ];
        yield 'строковый isCancel' => [
            WbResourceType::ORDERS_STATISTICS,
            ['srid' => 's-1', 'date' => '2026-08-30T22:18:04', 'isCancel' => 'false', 'nmId' => 1],
            'malformed_is_cancel',
        ];
        yield 'isCancel отсутствует' => [
            WbResourceType::ORDERS_STATISTICS,
            ['srid' => 's-1', 'date' => '2026-08-30T22:18:04', 'nmId' => 1],
            'malformed_is_cancel',
        ];
    }

    /**
     * Служебный маркер пустого окна — единственный ожидаемый повод ничего не
     * разобрать, и он не должен попадать в очередь на разбор.
     */
    public function testEmptyWindowMarkerIsNotReportedAsSkipped(): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), [
            ['_ingestion_empty' => true, '_ingestion_resource' => WbResourceType::ORDERS_STATISTICS],
        ]);

        self::assertSame([], $batch->orders);
        self::assertSame([], $batch->skipped);
    }

    /**
     * Деньги переводятся через строку, а не умножением float на 100: двоичная
     * дробь даёт 189999 вместо 190000.
     */
    #[DataProvider('majorPriceProvider')]
    public function testMajorPriceConversion(int|float|string $value, string $expected): void
    {
        $rows = [[
            'srid' => 's-1',
            'date' => '2026-08-30T22:18:04',
            'isCancel' => false,
            'nmId' => 1,
            'finishedPrice' => $value,
        ]];

        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), $rows);

        self::assertSame($expected, $batch->orders[0]->attributes['finished_price_minor']);
    }

    /**
     * @return iterable<string, array{int|float|string, string}>
     */
    public static function majorPriceProvider(): iterable
    {
        yield 'целые рубли' => [1900, '190000'];
        yield 'дробное значение' => [1900.5, '190050'];
        yield 'копейки' => [0.5, '50'];
        yield 'ноль' => [0, '0'];
        yield 'строка' => ['2715.00', '271500'];
        yield 'отрицательный ноль' => ['-0.00', '0'];
    }

    /**
     * @param list<\App\Ingestion\Application\DTO\MappedOrder> $orders
     */
    private function orderByExternalId(array $orders, string $externalId): \App\Ingestion\Application\DTO\MappedOrder
    {
        foreach ($orders as $order) {
            if ($order->externalId === $externalId) {
                return $order;
            }
        }

        self::fail('Заказ '.$externalId.' не найден.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketplaceRows(): array
    {
        $path = \dirname(__DIR__, 5).'/Fixtures/Marketplace/Orders/wb_marketplace_orders.json';
        $orders = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR)['orders'];

        $statusPath = \dirname(__DIR__, 5).'/Fixtures/Marketplace/Orders/wb_marketplace_orders_status.json';
        $statuses = [];
        foreach (json_decode((string) file_get_contents($statusPath), true, 512, \JSON_THROW_ON_ERROR)['orders'] as $status) {
            $statuses[$status['id']] = $status;
        }

        // Коннектор подмешивает статусы до записи в raw — воспроизводим это.
        $rows = [];
        foreach ($orders as $row) {
            $rows[] = isset($statuses[$row['id']])
                ? $row + ['_ingestion_status' => $statuses[$row['id']]]
                : $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statisticsRows(): array
    {
        $path = \dirname(__DIR__, 5).'/Fixtures/Marketplace/Orders/wb_statistics_orders.json';

        return json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Ровно на границе значение допустимо: отбраковка «на всякий случай»
     * теряла бы настоящие суммы.
     */
    #[DataProvider('bigintBoundaryProvider')]
    public function testValuesExactlyOnTheBigintBoundaryAreAccepted(string $price, string $expected): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_MARKETPLACE), [[
            'rid' => 'r-1',
            'createdAt' => '2026-08-30T19:18:04Z',
            'nmId' => 1,
            'price' => $price,
        ]]);

        self::assertSame([], $batch->skipped);
        self::assertSame($expected, $batch->orders[0]->items[0]->priceMinor);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bigintBoundaryProvider(): iterable
    {
        yield 'максимум' => ['9223372036854775807', '9223372036854775807'];
        yield 'минимум' => ['-9223372036854775808', '-9223372036854775808'];
    }

    /**
     * Границы проверяются и для РУБЛЁВОГО пути: у него своя конвертация, и
     * off-by-one в ней прошёл бы мимо тестов копеечного пути.
     */
    #[DataProvider('bigintMajorBoundaryProvider')]
    public function testMajorValuesOnTheBigintBoundary(string $finishedPrice, ?string $expected): void
    {
        $batch = (new WbOrderMapper())->map($this->rawRecord(WbResourceType::ORDERS_STATISTICS), [[
            'srid' => 's-1',
            'date' => '2026-08-30T22:18:04',
            'isCancel' => false,
            'nmId' => 1,
            'finishedPrice' => $finishedPrice,
        ]]);

        if (null === $expected) {
            self::assertSame([], $batch->orders);
            self::assertSame('malformed_price', $batch->skipped[0]['reason']);

            return;
        }

        self::assertSame([], $batch->skipped);
        self::assertSame($expected, $batch->orders[0]->attributes['finished_price_minor']);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function bigintMajorBoundaryProvider(): iterable
    {
        yield 'максимум в рублях' => ['92233720368547758.07', '9223372036854775807'];
        yield 'на копейку выше максимума' => ['92233720368547758.08', null];
        yield 'минимум в рублях' => ['-92233720368547758.08', '-9223372036854775808'];
        yield 'на копейку ниже минимума' => ['-92233720368547758.09', null];
    }

    private function rawRecord(string $resourceType): IngestRawRecord
    {
        return new IngestRawRecord(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-main',
            source: IngestSource::WILDBERRIES,
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
