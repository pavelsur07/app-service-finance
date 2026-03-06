<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistry;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\RowClassifierRegistry;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessMarketplaceRawDocumentAction
{
    public function __construct(
        private RowClassifierRegistry $classifierRegistry,
        private MarketplaceRawProcessorRegistry $processorRegistry,
        private MarketplaceRawDocumentRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ProcessMarketplaceRawDocumentCommand $command): void
    {
        $document = $this->repository->find($command->rawDocId);

        if ($document === null) {
            throw new \RuntimeException(sprintf('Raw document not found: %s', $command->rawDocId));
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

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = $classifier->classify($row);
            $bucketKey = $type->value;
            $buckets[$bucketKey][] = $row;

            if (count($buckets[$bucketKey]) >= 500) {
                $processor = $this->processorRegistry->get($type);
                $processor->processBatch($command->companyId, $marketplace, $buckets[$bucketKey]);
                $buckets[$bucketKey] = [];
                $this->entityManager->clear();
            }
        }

        foreach ($buckets as $bucketKey => $bucketRows) {
            if ($bucketRows === []) {
                continue;
            }

            $type = StagingRecordType::from($bucketKey);
            $processor = $this->processorRegistry->get($type);
            $processor->processBatch($command->companyId, $marketplace, $bucketRows);
            $this->entityManager->clear();
        }
    }
}
