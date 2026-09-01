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
        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows())->orders;

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
        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows())->orders;
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
        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows())->orders;

        self::assertSame('posting_on_way_to_city', $orders[0]->rawSubstatus);
    }

    public function testSchemeComesFromResourceTypeNotFromPayload(): void
    {
        $mapper = new OzonOrderMapper();

        self::assertSame(IngestOrderScheme::FBO, $mapper->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows())->orders[0]->scheme);
        self::assertSame(IngestOrderScheme::FBS, $mapper->map($this->rawRecord(OzonResourceType::ORDERS_FBS), $this->fixtureRows())->orders[0]->scheme);
    }

    /**
     * Цена приходит строкой рублей и хранится в копейках: денежная арифметика
     * во float — источник расхождений.
     */
    public function testPriceIsConvertedToMinorUnits(): void
    {
        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows())->orders;
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
        $orders = (new OzonOrderMapper())->map(
            $this->rawRecord(OzonResourceType::ORDERS_FBO),
            $this->rowsWithPrice($price),
        )->orders;

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
        // Отсутствие цены допустимо: поле не обязано присутствовать.
        yield 'null' => [null, null];
        // "-0" — то же число, но другая строка: на сравнении денежных значений
        // такая пара молча разъезжается.
        yield 'отрицательный ноль' => ['-0.00', '0'];
        yield 'минус ноль без дробной части' => ['-0', '0'];
        yield 'отрицательная цена' => ['-15.50', '-1550'];
    }

    /**
     * Присутствующая, но неразбираемая цена — испорченная строка, а не
     * «бесплатно». Молчаливый null обнулял бы позицию в любом денежном
     * расчёте, и заметить это было бы нечем.
     */
    #[DataProvider('malformedPriceCases')]
    public function testMalformedPriceSkipsThePostingInsteadOfNullingIt(mixed $price): void
    {
        $batch = (new OzonOrderMapper())->map(
            $this->rawRecord(OzonResourceType::ORDERS_FBO),
            $this->rowsWithPrice($price),
        );

        self::assertSame([], $batch->orders);
        self::assertSame([['reason' => 'malformed_product_price', 'hint' => 'p-1']], $batch->skipped);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedPriceCases(): iterable
    {
        yield 'не число' => ['бесплатно'];
        yield 'пустая строка' => [''];
        yield 'массив' => [[]];
    }

    /**
     * Строгая типизация позиции: приведение произвольного значения к 0 или к
     * true даёт правдоподобную, но выдуманную строку заказа.
     *
     * @param array<string, mixed> $product
     */
    #[DataProvider('malformedProductCases')]
    public function testMalformedProductSkipsThePosting(array $product, string $expectedReason): void
    {
        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [[
            'posting_number' => 'p-1',
            'status' => 'delivering',
            'created_at' => '2026-09-01T10:00:00Z',
            'products' => [$product],
        ]]);

        self::assertSame([], $batch->orders);
        self::assertSame([['reason' => $expectedReason, 'hint' => 'p-1']], $batch->skipped);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedProductCases(): iterable
    {
        yield 'нецелое количество' => [
            ['sku' => 1, 'quantity' => '2.5', 'price' => '10.00'],
            'malformed_product_quantity',
        ];
        yield 'количество не число' => [
            ['sku' => 1, 'quantity' => 'две штуки', 'price' => '10.00'],
            'malformed_product_quantity',
        ];
        yield 'отрицательное количество' => [
            ['sku' => 1, 'quantity' => -1, 'price' => '10.00'],
            'malformed_product_quantity',
        ];
        // "false" при приведении становится true и ровно переворачивает признак.
        yield 'строковый boolean выкупа' => [
            ['sku' => 1, 'quantity' => 1, 'price' => '10.00', 'is_marketplace_buyout' => 'false'],
            'malformed_product_buyout',
        ];
    }

    public function testMalformedProductsContainerSkipsThePosting(): void
    {
        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [[
            'posting_number' => 'p-1',
            'status' => 'delivering',
            'created_at' => '2026-09-01T10:00:00Z',
            'products' => 'сломано',
        ]]);

        self::assertSame([], $batch->orders);
        self::assertSame([['reason' => 'malformed_products', 'hint' => 'p-1']], $batch->skipped);
    }

    /**
     * Регрессия: new DateTimeImmutable() датой ничего не проверяет — он примет
     * относительную строку и молча нормализует несуществующее число. Заказ
     * получил бы правдоподобную, но выдуманную дату, а raw пометился бы DONE.
     */
    #[DataProvider('invalidDateCases')]
    public function testInvalidCreatedAtIsRejectedInsteadOfInvented(string $createdAt): void
    {
        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [[
            'posting_number' => 'p-1',
            'status' => 'delivering',
            'created_at' => $createdAt,
        ]]);

        self::assertSame([], $batch->orders);
        self::assertSame([['reason' => 'unparsable_created_at', 'hint' => 'p-1']], $batch->skipped);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDateCases(): iterable
    {
        yield 'относительная строка' => ['tomorrow'];
        yield 'несуществующее число' => ['2026-02-30T10:00:00Z'];
        yield 'без таймзоны' => ['2026-09-01T10:00:00'];
        yield 'только дата' => ['2026-09-01'];
        yield '25-й час' => ['2026-09-01T25:00:00Z'];
    }

    /**
     * Микросекунды — реальный формат Ozon, проверено на выгрузке.
     */
    public function testMicrosecondTimestampIsAccepted(): void
    {
        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [[
            'posting_number' => 'p-1',
            'status' => 'delivering',
            'created_at' => '2026-08-30T17:49:41.153418Z',
            'products' => [['sku' => 1, 'quantity' => 1, 'price' => '10.00']],
        ]]);

        self::assertSame([], $batch->skipped);
        self::assertSame('2026-08-30T17:49:41+00:00', $batch->orders[0]->orderedAt->format(\DATE_ATOM));
    }

    /**
     * Регрессия: ключ обрезался до 120 символов вместе с номером повторения,
     * поэтому длинный offer_id давал #0 и #1 с одинаковым lineKey, а два
     * разных идентификатора с общим началом схлопывались в одну позицию.
     */
    public function testLongOfferIdsKeepDistinctLineKeys(): void
    {
        $prefix = str_repeat('A', 200);

        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [[
            'posting_number' => 'p-1',
            'status' => 'delivering',
            'created_at' => '2026-09-01T10:00:00Z',
            'products' => [
                ['offer_id' => $prefix.'-1', 'quantity' => 1, 'price' => '10.00'],
                ['offer_id' => $prefix.'-2', 'quantity' => 1, 'price' => '10.00'],
                ['offer_id' => $prefix.'-1', 'quantity' => 1, 'price' => '10.00'],
            ],
        ]]);

        $keys = array_map(static fn ($i): string => $i->lineKey, $batch->orders[0]->items);

        self::assertCount(3, array_unique($keys), 'Три позиции — три разных ключа.');
        foreach ($keys as $key) {
            self::assertLessThanOrEqual(120, mb_strlen($key), 'Ключ обязан помещаться в колонку.');
        }
    }

    /**
     * Регрессия: `$row['products'] ?? []` превращал отсутствующий или
     * null-евый список в корректный пустой заказ. Позиции терялись бесследно,
     * raw помечался DONE, курсор уходил вперёд.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('missingProductsCases')]
    public function testMissingProductsContainerSkipsThePosting(array $row): void
    {
        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [$row]);

        self::assertSame([], $batch->orders);
        self::assertSame([['reason' => 'malformed_products', 'hint' => 'p-1']], $batch->skipped);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function missingProductsCases(): iterable
    {
        $base = ['posting_number' => 'p-1', 'status' => 'delivering', 'created_at' => '2026-09-01T10:00:00Z'];

        yield 'ключа нет' => [$base];
        yield 'null' => [$base + ['products' => null]];
        yield 'строка' => [$base + ['products' => 'сломано']];
    }

    /**
     * Позиция без sku и без offer_id опознаётся только по номеру строки — то
     * есть ровно тем позиционным ключом, ради ухода от которого вводился
     * lineKey. Такой posting отклоняется целиком.
     */
    public function testProductWithoutAnyIdentitySkipsThePosting(): void
    {
        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [[
            'posting_number' => 'p-1',
            'status' => 'delivering',
            'created_at' => '2026-09-01T10:00:00Z',
            'products' => [['name' => 'Товар без артикула', 'quantity' => 1, 'price' => '10.00']],
        ]]);

        self::assertSame([], $batch->orders);
        self::assertSame([['reason' => 'missing_product_identity', 'hint' => 'p-1']], $batch->skipped);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsWithPrice(mixed $price): array
    {
        return [[
            'posting_number' => 'p-1',
            'status' => 'delivering',
            'created_at' => '2026-09-01T10:00:00Z',
            'products' => [['sku' => 1, 'quantity' => 1, 'price' => $price, 'currency_code' => 'RUB']],
        ]];
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

        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $rows)->orders;

        self::assertCount(2, $orders[0]->items);
        self::assertSame([0, 1], array_map(static fn ($i): int => $i->lineNo, $orders[0]->items));
    }

    /**
     * Данные для резолва листинга должны попасть в sourceData: OzonListingResolver
     * читает именно offer_id и sku.
     */
    public function testItemCarriesResolverInputInSourceData(): void
    {
        $orders = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), $this->fixtureRows())->orders;
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
        self::assertSame([], (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBS), [])->orders);
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

    /**
     * Регрессия: строка без обязательных полей раньше исчезала молча. Курсор
     * после нормализации уже уехал, поэтому пропуск был постоянным и ничем не
     * отличался от «заказов в окне не было».
     */
    public function testRowWithoutPostingNumberIsReportedAsSkipped(): void
    {
        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [
            ['status' => 'delivering'],
        ]);

        self::assertSame([], $batch->orders);
        self::assertSame([['reason' => 'missing_posting_number', 'hint' => null]], $batch->skipped);
    }

    public function testRowWithoutStatusIsReportedAsSkipped(): void
    {
        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [
            ['posting_number' => '111-2222-3', 'created_at' => '2026-09-01T10:00:00Z'],
        ]);

        self::assertSame([], $batch->orders);
        self::assertSame([['reason' => 'missing_status', 'hint' => '111-2222-3']], $batch->skipped);
    }

    /**
     * Дата заказа не подменяется временем загрузки: подстановка тихо сдвигала
     * бы заказ в сегодняшний день и искажала любую аналитику по датам.
     */
    public function testUnparsableCreatedAtIsSkippedInsteadOfSubstituted(): void
    {
        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [
            ['posting_number' => '111-2222-3', 'status' => 'delivering', 'created_at' => 'позавчера'],
        ]);

        self::assertSame([], $batch->orders);
        self::assertSame([['reason' => 'unparsable_created_at', 'hint' => '111-2222-3']], $batch->skipped);
    }

    /**
     * Служебный маркер пустого окна — единственный ожидаемый повод ничего не
     * разобрать, и он не должен попадать в очередь на разбор.
     */
    public function testEmptyWindowMarkerIsNotReportedAsSkipped(): void
    {
        $batch = (new OzonOrderMapper())->map($this->rawRecord(OzonResourceType::ORDERS_FBO), [
            ['_ingestion_empty' => true, '_ingestion_resource' => OzonResourceType::ORDERS_FBO],
        ]);

        self::assertSame([], $batch->orders);
        self::assertSame([], $batch->skipped);
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
