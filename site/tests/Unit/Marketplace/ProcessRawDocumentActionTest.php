<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\Processor\MarketplaceRawDocumentProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Application\ProcessRawDocumentAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceRawDocumentMarketplaceQueryInterface;
use PHPUnit\Framework\TestCase;

final class ProcessRawDocumentActionTest extends TestCase
{
    public function testRoutesToRegistryProcessorAndReturnsResult(): void
    {
        $query = $this->createMock(MarketplaceRawDocumentMarketplaceQueryInterface::class);
        $registry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processor = $this->createMock(MarketplaceRawDocumentProcessorInterface::class);

        $query
            ->expects(self::once())
            ->method('getMarketplaceValue')
            ->with('company-1', 'doc-1')
            ->willReturn('ozon');

        $registry
            ->expects(self::once())
            ->method('getForDocument')
            ->with('ozon', MarketplaceType::OZON, 'costs', null)
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
            ->method('getMarketplaceValue')
            ->with('company-1', 'doc-missing')
            ->willReturn(null);

        $registry->expects(self::never())->method('getForDocument');

        $action = new ProcessRawDocumentAction($query, $registry);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Raw document not found: doc-missing');

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-missing', 'costs'));
    }
}
