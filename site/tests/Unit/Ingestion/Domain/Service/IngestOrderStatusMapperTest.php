<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain\Service;

use App\Ingestion\Domain\Service\IngestOrderStatusMapper;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IngestOrderStatusMapperTest extends TestCase
{
    private IngestOrderStatusMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new IngestOrderStatusMapper();
    }

    /**
     * Значения, реально наблюдённые в выгрузке 2026-09-01: 87 delivering,
     * 6 delivered, 5 cancelled, 2 awaiting_deliver.
     */
    #[DataProvider('ozonFboObservedStatuses')]
    public function testMapsObservedOzonFboStatuses(string $raw, IngestOrderStatus $expected): void
    {
        self::assertSame($expected, $this->mapper->map(IngestSource::OZON, IngestOrderScheme::FBO, $raw));
    }

    /**
     * @return iterable<string, array{string, IngestOrderStatus}>
     */
    public static function ozonFboObservedStatuses(): iterable
    {
        yield 'awaiting_deliver' => ['awaiting_deliver', IngestOrderStatus::ORDERED];
        yield 'delivering' => ['delivering', IngestOrderStatus::SHIPPED];
        yield 'delivered' => ['delivered', IngestOrderStatus::DELIVERED];
        yield 'cancelled' => ['cancelled', IngestOrderStatus::CANCELLED];
    }

    /**
     * Словарь FBS засеян из документации: в выгрузке было НОЛЬ FBS-отправлений,
     * поэтому проверить его на реальных данных не удалось. Имя теста намеренно
     * называет это вслух, чтобы факт не растворился.
     */
    public function testFbsDictionaryIsDocumentationDerivedAndUnverified(): void
    {
        self::assertSame(
            IngestOrderStatus::ORDERED,
            $this->mapper->map(IngestSource::OZON, IngestOrderScheme::FBS, 'awaiting_packaging'),
        );
        self::assertSame(
            IngestOrderStatus::SHIPPED,
            $this->mapper->map(IngestSource::OZON, IngestOrderScheme::FBS, 'delivering'),
        );
    }

    /**
     * Главная находка по WB: статус — ПАРА осей. Наблюдалась комбинация
     * new / canceled_by_client, где supplierStatus говорит «новый», а wbStatus
     * — «отменён клиентом». Одной осью это не выразить.
     */
    #[DataProvider('wbObservedPairs')]
    public function testMapsObservedWbStatusPairs(string $supplier, string $wb, IngestOrderStatus $expected): void
    {
        $raw = IngestOrderStatusMapper::encodeWbStatus($supplier, $wb);

        self::assertSame($expected, $this->mapper->map(IngestSource::WILDBERRIES, IngestOrderScheme::FBS, $raw));
    }

    /**
     * @return iterable<string, array{string, string, IngestOrderStatus}>
     */
    public static function wbObservedPairs(): iterable
    {
        yield 'complete/sorted' => ['complete', 'sorted', IngestOrderStatus::SHIPPED];
        yield 'new/waiting' => ['new', 'waiting', IngestOrderStatus::ORDERED];
        yield 'new/canceled_by_client' => ['new', 'canceled_by_client', IngestOrderStatus::CANCELLED];
    }

    public function testWbSoldMeansDelivered(): void
    {
        $raw = IngestOrderStatusMapper::encodeWbStatus('complete', 'sold');

        self::assertSame(
            IngestOrderStatus::DELIVERED,
            $this->mapper->map(IngestSource::WILDBERRIES, IngestOrderScheme::FBS, $raw),
        );
    }

    /**
     * Поток statistics не отдаёт статуса вовсе — только признак отмены.
     */
    public function testWbStatisticsCancelFlag(): void
    {
        self::assertSame(
            IngestOrderStatus::CANCELLED,
            $this->mapper->map(IngestSource::WILDBERRIES, IngestOrderScheme::FBO, IngestOrderStatusMapper::encodeWbCancelFlag(true)),
        );
        self::assertSame(
            IngestOrderStatus::ORDERED,
            $this->mapper->map(IngestSource::WILDBERRIES, IngestOrderScheme::FBO, IngestOrderStatusMapper::encodeWbCancelFlag(false)),
        );
    }

    /**
     * Неизвестное значение обязано деградировать в видимое UNKNOWN, а не в NULL
     * и не в исключение: NULL одновременно ломает данные и прячет поломку.
     */
    public function testUnknownRawStatusDegradesToUnknown(): void
    {
        self::assertSame(
            IngestOrderStatus::UNKNOWN,
            $this->mapper->map(IngestSource::OZON, IngestOrderScheme::FBO, 'какой_то_новый_статус'),
        );
        self::assertSame(
            IngestOrderStatus::UNKNOWN,
            $this->mapper->map(IngestSource::WILDBERRIES, IngestOrderScheme::FBS, 'мусор'),
        );
    }

    /**
     * Кодировка WB обратима: раз это единственное место, где rawStatus —
     * составная строка, притворяться, что WB отдал одно поле, нельзя.
     */
    public function testWbStatusEncodingIsReversible(): void
    {
        $raw = IngestOrderStatusMapper::encodeWbStatus('complete', 'sorted');

        self::assertSame(['supplierStatus' => 'complete', 'wbStatus' => 'sorted'], IngestOrderStatusMapper::decodeWbStatus($raw));
    }

    /**
     * Терминальность спрашивают из выборки, монитора и апсерта — определение
     * обязано быть одно.
     */
    public function testTerminalStatusesAreExactlyThree(): void
    {
        $terminal = array_values(array_filter(
            IngestOrderStatus::cases(),
            static fn (IngestOrderStatus $s): bool => $s->isTerminal(),
        ));

        self::assertSame(
            [IngestOrderStatus::DELIVERED, IngestOrderStatus::CANCELLED, IngestOrderStatus::RETURNED],
            $terminal,
        );
    }
}
