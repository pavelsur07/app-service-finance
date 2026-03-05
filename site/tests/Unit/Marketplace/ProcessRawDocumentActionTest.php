<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessRawDocumentAction;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistry;
use App\Marketplace\Infrastructure\Query\MarketplaceRawDocumentMarketplaceQuery;
use PHPUnit\Framework\TestCase;

final class ProcessRawDocumentActionTest extends TestCase
{
    public function testRoutesToRegistryProcessorAndReturnsResult(): void
    {
        $query = $this->createMock(MarketplaceRawDocumentMarketplaceQuery::class);
        $registry = $this->createMock(MarketplaceRawProcessorRegistry::class);
        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);

        $query
            ->expects(self::once())
            ->method('getMarketplaceValue')
            ->with('company-1', 'doc-1')
            ->willReturn('ozon');

        $registry
            ->expects(self::once())
            ->method('get')
            ->with('ozon', 'sales')
            ->willReturn($processor);

        $processor
            ->expects(self::once())
            ->method('process')
            ->with('company-1', 'doc-1')
            ->willReturn(7);

        $action = new ProcessRawDocumentAction($query, $registry);

        self::assertSame(7, $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'sales')));
    }

    public function testThrowsWhenRawDocumentMissing(): void
    {
        $query = $this->createMock(MarketplaceRawDocumentMarketplaceQuery::class);
        $registry = $this->createMock(MarketplaceRawProcessorRegistry::class);

        $query
            ->expects(self::once())
            ->method('getMarketplaceValue')
            ->with('company-1', 'doc-missing')
            ->willReturn(null);

        $registry->expects(self::never())->method('get');

        $action = new ProcessRawDocumentAction($query, $registry);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Raw document not found: doc-missing');

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-missing', 'sales'));
    }
}
