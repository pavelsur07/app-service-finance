<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Marketplace\Application\Processor\MarketplaceRawProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistry;
use App\Marketplace\Application\Processor\OzonCostsRawProcessor;
use App\Marketplace\Application\Processor\OzonReturnsRawProcessor;
use App\Marketplace\Application\Processor\OzonSalesRawProcessor;
use App\Marketplace\Application\Processor\WbCostsRawProcessor;
use App\Marketplace\Application\Processor\WbReturnsRawProcessor;
use App\Marketplace\Application\Processor\WbSalesRawProcessor;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Сосуществование форматов: старые записи обрабатываются старым обработчиком,
 * новые загрузки — новым.
 *
 * Реестр берёт ПЕРВЫЙ подошедший процессор, поэтому пока легаси-процессоры не
 * различают формат явно, будущий процессор by-day будет затенён порядком
 * сервисов в контейнере. Эти тесты закрепляют различение.
 *
 * `supports()` — чистый метод и зависимостей не трогает, поэтому процессоры
 * создаются без конструктора: собирать по 7–9 моков ради проверки одного
 * предиката было бы шумом без пользы.
 */
final class RawProcessorFormatIsolationTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, StagingRecordType}>
     */
    public static function ozonProcessors(): iterable
    {
        yield 'sales' => [OzonSalesRawProcessor::class, StagingRecordType::SALE];
        yield 'costs' => [OzonCostsRawProcessor::class, StagingRecordType::COST];
        yield 'returns' => [OzonReturnsRawProcessor::class, StagingRecordType::RETURN];
    }

    /**
     * @return iterable<string, array{class-string, StagingRecordType}>
     */
    public static function wbProcessors(): iterable
    {
        yield 'sales' => [WbSalesRawProcessor::class, StagingRecordType::SALE];
        yield 'costs' => [WbCostsRawProcessor::class, StagingRecordType::COST];
        yield 'returns' => [WbReturnsRawProcessor::class, StagingRecordType::RETURN];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('ozonProcessors')]
    public function testOzonProcessorClaimsLegacyFormat(string $class, StagingRecordType $type): void
    {
        self::assertTrue(
            $this->processor($class)->supports($type, MarketplaceType::OZON, '', MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3),
        );
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('ozonProcessors')]
    public function testOzonProcessorStillClaimsDocumentWithUnknownFormat(string $class, StagingRecordType $type): void
    {
        // Регрессия: 967 документов на PROD и все вызывающие, которые формат не
        // передают, обязаны продолжать работать ровно как прежде.
        self::assertTrue($this->processor($class)->supports($type, MarketplaceType::OZON, '', null));
        self::assertTrue($this->processor($class)->supports($type, MarketplaceType::OZON));
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('ozonProcessors')]
    public function testOzonProcessorClaimsOnlyItsOwnFormatWhenFormatIsGiven(string $class, StagingRecordType $type): void
    {
        // Не только by-day: переданный формат, отличный от снятого v3, означает
        // документ другого поколения, и молча обрабатывать его нельзя.
        foreach ([
            MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY,
            MarketplaceRawFormat::OZON_REALIZATION_V2,
            MarketplaceRawFormat::OZON_MUTUAL_SETTLEMENT_V1,
        ] as $foreign) {
            self::assertFalse(
                $this->processor($class)->supports($type, MarketplaceType::OZON, '', $foreign),
                sprintf('%s не должен обслуживать формат %s', $class, $foreign->value),
            );
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('wbProcessors')]
    public function testWildberriesBehaviourIsUnchangedOnBothItsFormats(string $class, StagingRecordType $type): void
    {
        // У WB на одном document_type уже сосуществуют два формата, и оба
        // обслуживаются теми же процессорами. Эта задача их не трогает.
        foreach ([
            MarketplaceRawFormat::WB_FINANCE_SALES_REPORTS_DETAILED,
            MarketplaceRawFormat::WB_REPORT_DETAIL_BY_PERIOD,
            null,
        ] as $format) {
            self::assertTrue(
                $this->processor($class)->supports($type, MarketplaceType::WILDBERRIES, '', $format),
                sprintf('%s должен обслуживать формат %s', $class, null !== $format ? $format->value : 'null'),
            );
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('ozonProcessors')]
    public function testLegacyStringKindPathIsUntouched(string $class, StagingRecordType $type): void
    {
        $kind = match ($type) {
            StagingRecordType::SALE => 'sales',
            StagingRecordType::COST => 'costs',
            StagingRecordType::RETURN => 'returns',
            default => 'other',
        };

        self::assertTrue($this->processor($class)->supports(MarketplaceType::OZON->value, MarketplaceType::OZON, $kind));
    }

    public function testRegistryDoesNotHandOutLegacyProcessorForAccrualByDay(): void
    {
        // Проверка на уровне реестра, а не отдельного supports(): именно здесь
        // проявилось бы затенение — реестр возвращает первый подошедший.
        $registry = new MarketplaceRawProcessorRegistry([
            $this->processor(OzonSalesRawProcessor::class),
            $this->processor(OzonCostsRawProcessor::class),
            $this->processor(OzonReturnsRawProcessor::class),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ozon::v1/finance/accrual/by-day');

        $registry->get(StagingRecordType::SALE, MarketplaceType::OZON, 'sales', MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY);
    }

    public function testRegistryStillHandsOutLegacyProcessorForLegacyDocument(): void
    {
        $legacySales = $this->processor(OzonSalesRawProcessor::class);
        $registry = new MarketplaceRawProcessorRegistry([$legacySales]);

        self::assertSame(
            $legacySales,
            $registry->get(StagingRecordType::SALE, MarketplaceType::OZON, 'sales', MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3),
        );
        self::assertSame(
            $legacySales,
            $registry->get(StagingRecordType::SALE, MarketplaceType::OZON, 'sales'),
        );
    }

    /**
     * @param class-string $class
     */
    private function processor(string $class): MarketplaceRawProcessorInterface
    {
        $processor = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(MarketplaceRawProcessorInterface::class, $processor);

        return $processor;
    }
}
