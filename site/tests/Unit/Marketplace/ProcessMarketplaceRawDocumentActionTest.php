<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Company\Entity\Company;
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
use App\Marketplace\Repository\MarketplaceCostRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
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
            $this->createMock(\App\Marketplace\Repository\MarketplaceSaleRepository::class),
            $this->createMock(\App\Marketplace\Repository\MarketplaceReturnRepository::class),
            $this->createMock(MarketplaceCostRepository::class),
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
        $document->method('getRawData')->willReturn([['x' => 1]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $classifier = $this->createMock(RowClassifierInterface::class);
        $classifier->method('classify')->willReturn(StagingRecordType::SALE);
        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->method('get')->willReturn($classifier);

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            $repository,
            $this->createMock(\App\Marketplace\Repository\MarketplaceSaleRepository::class),
            $this->createMock(\App\Marketplace\Repository\MarketplaceReturnRepository::class),
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown kind "unknown"');

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'unknown'));
    }

    public function testOrdinaryWbCostsUseProcessDirectlyWithoutReportingForceConflict(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([['x' => 1]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::WILDBERRIES);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

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
            ->with(StagingRecordType::COST, MarketplaceType::WILDBERRIES)
            ->willReturn($processor);

        $costRepository = $this->createMock(MarketplaceCostRepository::class);
        $costRepository->expects(self::never())->method('countDocumentLinkedByRawDocument');

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $processorRegistry,
            $repository,
            $this->createMock(\App\Marketplace\Repository\MarketplaceSaleRepository::class),
            $this->createMock(\App\Marketplace\Repository\MarketplaceReturnRepository::class),
            $costRepository,
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $result = $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'costs'));

        self::assertSame(42, $result->processedRows);
        self::assertSame(0, $result->preservedLinkedRows);
    }

    public function testForceReprocessWbSalesCallsDeleteByRawDocument(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([['x' => 1]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::WILDBERRIES);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->expects(self::once())
            ->method('deleteByRawDocument')
            ->with($company, MarketplaceType::WILDBERRIES, 'doc-1');

        $returnRepository = $this->createMock(MarketplaceReturnRepository::class);
        $returnRepository->expects(self::never())->method('deleteByRawDocument');

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->expects(self::once())->method('processBatch');
        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->method('get')->willReturn($processor);

        $classifier = $this->createMock(RowClassifierInterface::class);
        $classifier->method('classify')->willReturn(StagingRecordType::SALE);
        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->method('get')->willReturn($classifier);

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $processorRegistry,
            $repository,
            $saleRepository,
            $returnRepository,
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'sales', true));
    }

    public function testForceReprocessWbReturnsCallsDeleteByRawDocument(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([['x' => 1]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::WILDBERRIES);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->expects(self::never())->method('deleteByRawDocument');

        $returnRepository = $this->createMock(MarketplaceReturnRepository::class);
        $returnRepository->expects(self::once())
            ->method('deleteByRawDocument')
            ->with($company, MarketplaceType::WILDBERRIES, 'doc-2');

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->expects(self::once())->method('processBatch');
        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->method('get')->willReturn($processor);

        $classifier = $this->createMock(RowClassifierInterface::class);
        $classifier->method('classify')->willReturn(StagingRecordType::RETURN);
        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->method('get')->willReturn($classifier);

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $processorRegistry,
            $repository,
            $saleRepository,
            $returnRepository,
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-2', 'returns', true));
    }

    public function testWbCostsPartialReprocessSucceedsAndReportsPreservedLinkedRows(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getMarketplace')->willReturn(MarketplaceType::WILDBERRIES);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->expects(self::once())->method('process')->with('company-1', 'doc-costs')->willReturn(7);
        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->expects(self::once())->method('get')->with(StagingRecordType::COST, MarketplaceType::WILDBERRIES)->willReturn($processor);

        $costRepository = $this->createMock(MarketplaceCostRepository::class);
        $costRepository->expects(self::once())
            ->method('countDocumentLinkedByRawDocument')
            ->with($company, MarketplaceType::WILDBERRIES, 'doc-costs')
            ->willReturn(2);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement');

        $logger = $this->createMock(AppLogger::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'WB raw document partially reprocessed; linked rows preserved',
                self::callback(static fn (array $context): bool => $context['preservedLinkedRows'] === 2
                    && $context['processedRows'] === 7
                    && $context['kind'] === 'costs'),
            );

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $processorRegistry,
            $repository,
            $this->createMock(MarketplaceSaleRepository::class),
            $this->createMock(MarketplaceReturnRepository::class),
            $costRepository,
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $connection,
            $logger,
        );

        // Частичная переобработка — успех: строки обработаны, сохранённые linked rows
        // возвращены счётчиком, а не исключением.
        $result = $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-costs', 'costs', true));

        self::assertSame(7, $result->processedRows);
        self::assertSame(2, $result->preservedLinkedRows);
    }

    public function testForceReprocessOzonDoesNotCallWbDeleteByRawDocument(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->expects(self::never())->method('deleteByRawDocument');

        $returnRepository = $this->createMock(MarketplaceReturnRepository::class);
        $returnRepository->expects(self::never())->method('deleteByRawDocument');

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->expects(self::never())->method('process');
        $processor->expects(self::never())->method('processBatch');

        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->expects(self::once())->method('get')->with(StagingRecordType::SALE, MarketplaceType::OZON)->willReturn($processor);

        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->expects(self::once())->method('get');

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $processorRegistry,
            $repository,
            $saleRepository,
            $returnRepository,
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-3', 'sales', true));
    }
}
