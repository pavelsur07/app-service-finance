<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\RowClassifierRegistryInterface;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Выполняет один step run для указанного raw document.
 *
 * Целевой контракт daily pipeline:
 * - покрывает только шаги sales / returns / costs;
 * - реализация (realization) намеренно исключена из daily pipeline;
 * - retry шага допустим и не должен менять контракт существующего ручного flow.
 */
#[AsMessageHandler]
final readonly class ProcessMarketplaceRawDocumentAction
{
    public function __construct(
        private RowClassifierRegistryInterface $classifierRegistry,
        private MarketplaceRawProcessorRegistryInterface $processorRegistry,
        private MarketplaceRawDocumentRepository $repository,
        private EntityManagerInterface $entityManager,
        private MarketplaceCostCategoryResolver $costCategoryResolver,
    ) {
    }

    public function __invoke(ProcessMarketplaceRawDocumentCommand $command): int
    {
        $document = $this->repository->find($command->rawDocId);

        if ($document === null) {
            throw new \RuntimeException(sprintf('Raw document not found: %s', $command->rawDocId));
        }

        $kindToBucketKey = [
            'sales' => StagingRecordType::SALE->value,
            'returns' => StagingRecordType::RETURN->value,
            'costs' => StagingRecordType::COST->value,
        ];

        $targetBucketKey = $kindToBucketKey[$command->kind] ?? null;

        if ($targetBucketKey === null) {
            throw new \InvalidArgumentException(
                sprintf('Unknown kind "%s". Allowed: sales, returns, costs.', $command->kind)
            );
        }

        $buckets = [
            StagingRecordType::SALE->value => [],
            StagingRecordType::RETURN->value => [],
            StagingRecordType::COST->value => [],
            StagingRecordType::OTHER->value => [],
        ];

        $rows = $document->getRawData();
        if (
            $document->getMarketplace() === MarketplaceType::OZON
            && isset($rows['result']['operations'])
            && is_array($rows['result']['operations'])
        ) {
            $rows = $rows['result']['operations'];
        }

        $classifier = $this->classifierRegistry->get($document->getMarketplace());
        $marketplace = $document->getMarketplace();
        $totalProcessed = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = $classifier->classify($row);
            $bucketKey = $type->value;
            $buckets[$bucketKey][] = $row;

            if (count($buckets[$bucketKey]) >= 500) {
                if ($bucketKey === $targetBucketKey) {
                    $processor = $this->processorRegistry->get($type, $marketplace);
                    $processor->processBatch($command->companyId, $marketplace, $buckets[$bucketKey]);
                    $totalProcessed += count($buckets[$bucketKey]);
                    $this->entityManager->clear();
                }

                $buckets[$bucketKey] = [];
            }
        }

        foreach ($buckets as $bucketKey => $bucketRows) {
            if ($bucketKey !== $targetBucketKey) {
                continue;
            }

            if ($bucketRows === []) {
                continue;
            }

            $type = StagingRecordType::from($bucketKey);
            $processor = $this->processorRegistry->get($type, $marketplace);
            $processor->processBatch($command->companyId, $marketplace, $bucketRows);
            $totalProcessed += count($bucketRows);
            $this->entityManager->clear();
        }

        $this->costCategoryResolver->clearCache();

        return $totalProcessed;
    }
}
