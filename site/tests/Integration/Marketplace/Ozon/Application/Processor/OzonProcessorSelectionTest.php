<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Application\Processor;

use App\Marketplace\Application\Processor\MarketplaceRawProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistry;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Ozon\Application\Processor\OzonAccrualCostsRawProcessor;
use App\Marketplace\Ozon\Application\Processor\OzonAccrualReturnsRawProcessor;
use App\Marketplace\Ozon\Application\Processor\OzonAccrualSalesRawProcessor;
use App\Marketplace\Ozon\Application\Processor\OzonCostsRawProcessor;
use App\Marketplace\Ozon\Application\Processor\OzonReturnsRawProcessor;
use App\Marketplace\Ozon\Application\Processor\OzonSalesRawProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OzonProcessorSelectionTest extends KernelTestCase
{
    /** @return iterable<string, array{MarketplaceRawFormat, StagingRecordType, class-string<MarketplaceRawProcessorInterface>}> */
    public static function formats(): iterable
    {
        $v3 = MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3;
        $byDay = MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY;
        yield 'v3 sales' => [$v3, StagingRecordType::SALE, OzonSalesRawProcessor::class];
        yield 'v3 returns' => [$v3, StagingRecordType::RETURN, OzonReturnsRawProcessor::class];
        yield 'v3 costs' => [$v3, StagingRecordType::COST, OzonCostsRawProcessor::class];
        yield 'by-day sales' => [$byDay, StagingRecordType::SALE, OzonAccrualSalesRawProcessor::class];
        yield 'by-day returns' => [$byDay, StagingRecordType::RETURN, OzonAccrualReturnsRawProcessor::class];
        yield 'by-day costs' => [$byDay, StagingRecordType::COST, OzonAccrualCostsRawProcessor::class];
    }

    /** @param class-string<MarketplaceRawProcessorInterface> $expected */
    #[DataProvider('formats')]
    public function testRegisteredProcessorMatchesFormat(MarketplaceRawFormat $format, StagingRecordType $type, string $expected): void
    {
        $registry = self::getContainer()->get(MarketplaceRawProcessorRegistry::class);
        $processor = StagingRecordType::COST === $type
            ? $registry->getForDocument($type, MarketplaceType::OZON, 'costs', $format)
            : $registry->get($type, MarketplaceType::OZON, '', $format);
        self::assertInstanceOf($expected, $processor);
    }
}
