<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\Processor\MarketplaceRawDocumentProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Application\ProcessRawDocumentAction;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceRawDocumentMarketplaceQueryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessRawDocumentActionTest extends TestCase
{
    /** @return iterable<string, array{MarketplaceType, string, ?MarketplaceRawFormat}> */
    public static function costFormats(): iterable
    {
        yield 'Ozon by-day' => [MarketplaceType::OZON, MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value, MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY];
        yield 'Ozon v3' => [MarketplaceType::OZON, MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3->value, MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3];
        yield 'Ozon without endpoint' => [MarketplaceType::OZON, '   ', null];
        yield 'Wildberries' => [MarketplaceType::WILDBERRIES, MarketplaceRawFormat::WB_FINANCE_SALES_REPORTS_DETAILED->value, MarketplaceRawFormat::WB_FINANCE_SALES_REPORTS_DETAILED];
    }

    #[DataProvider('costFormats')]
    public function testRoutesToRegistryProcessorAndReturnsResult(MarketplaceType $marketplace, string $endpoint, ?MarketplaceRawFormat $format): void
    {
        $query = $this->createMock(MarketplaceRawDocumentMarketplaceQueryInterface::class);
        $registry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processor = $this->createMock(MarketplaceRawDocumentProcessorInterface::class);

        $query
            ->expects(self::once())
            ->method('findDocumentSource')
            ->with('company-1', 'doc-1')
            ->willReturn(['marketplace' => $marketplace->value, 'api_endpoint' => $endpoint]);

        $registry
            ->expects(self::once())
            ->method('getForDocument')
            ->with(StagingRecordType::COST, $marketplace, 'costs', $format)
            ->willReturn($processor);

        $processor
            ->expects(self::once())
            ->method('process')
            ->with('company-1', 'doc-1')
            ->willReturn(7);

        $action = new ProcessRawDocumentAction($query, $registry);

        self::assertSame(7, $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'costs')));
    }

    public function testThrowsWhenRawDocumentMissing(): void
    {
        $query = $this->createMock(MarketplaceRawDocumentMarketplaceQueryInterface::class);
        $registry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);

        $query
            ->expects(self::once())
            ->method('findDocumentSource')
            ->with('company-1', 'doc-missing')
            ->willReturn(null);

        $registry->expects(self::never())->method('getForDocument');

        $action = new ProcessRawDocumentAction($query, $registry);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Raw document not found: doc-missing');

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-missing', 'costs'));
    }
}
