<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Contract\RowClassifierInterface;
use App\Marketplace\Infrastructure\Normalizer\RowClassifierRegistryInterface;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProcessMarketplaceRawDocumentActionTest extends TestCase
{
    public function testThrowsWhenDocumentNotFound(): void
    {
        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn(null);

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            $repository,
            $this->createMock(EntityManagerInterface::class),
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
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown kind "unknown"');

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'unknown'));
    }

    public function testProcessesCostRows(): void
    {
        $rows = [
            ['operation_id' => '1', 'type' => 'services'],
            ['operation_id' => '2', 'type' => 'services'],
        ];

        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn($rows);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $classifier = $this->createMock(RowClassifierInterface::class);
        $classifier->method('classify')->willReturn(StagingRecordType::COST);

        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->method('get')->willReturn($classifier);

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor
            ->expects(self::once())
            ->method('processBatch')
            ->with('company-1', MarketplaceType::OZON, $rows);

        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->method('get')->willReturn($processor);

        $em = $this->createMock(EntityManagerInterface::class);

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $processorRegistry,
            $repository,
            $em,
        );

        $result = $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'costs'));

        self::assertSame(2, $result);
    }
}
