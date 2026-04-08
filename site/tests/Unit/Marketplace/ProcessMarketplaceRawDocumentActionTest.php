<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Contract\RowClassifierInterface;
use App\Marketplace\Infrastructure\Normalizer\RowClassifierRegistryInterface;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Shared\Service\AppLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProcessMarketplaceRawDocumentActionTest extends TestCase
{
    private function createCostCategoryResolver(): MarketplaceCostCategoryResolver
    {
        return new MarketplaceCostCategoryResolver(
            $this->createMock(MarketplaceCostCategoryRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
    }

    public function testThrowsWhenDocumentNotFound(): void
    {
        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn(null);

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            $repository,
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Raw document not found: missing-id');

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'missing-id', 'costs'));
    }

    public function testThrowsOnUnknownKind(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $classifier = $this->createMock(RowClassifierInterface::class);
        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->method('get')->willReturn($classifier);

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            $repository,
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown kind "unknown"');

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'unknown'));
    }

    public function testCostsKindUsesProcessDirectly(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor
            ->expects(self::once())
            ->method('process')
            ->with('company-1', 'doc-1')
            ->willReturn(42);
        $processor
            ->expects(self::never())
            ->method('processBatch');

        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry
            ->expects(self::once())
            ->method('get')
            ->with(StagingRecordType::COST, MarketplaceType::OZON)
            ->willReturn($processor);

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $processorRegistry,
            $repository,
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $result = $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'costs'));

        self::assertSame(42, $result);
    }
}
