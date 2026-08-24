<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\DTO\ProcessRawDocumentResult;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Contract\RowClassifierInterface;
use App\Marketplace\Infrastructure\Normalizer\RowClassifierRegistryInterface;
use App\Marketplace\Repository\MarketplaceCostRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use App\Shared\Service\AppLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Выполняет один step run для указанного raw document.
 *
 * Целевой контракт daily pipeline:
 * - покрывает только шаги sales / returns / costs;
 * - реализация (realization) намеренно исключена из daily pipeline;
 * - retry шага допустим и не должен менять контракт существующего ручного flow;
 * - частичная переобработка WB (сохранены linked rows) — успех шага; количество
 *   сохранённых строк возвращается в результате, а не сигнализируется исключением.
 */
#[AsMessageHandler]
final readonly class ProcessMarketplaceRawDocumentAction
{
    public function __construct(
        private RowClassifierRegistryInterface $classifierRegistry,
        private MarketplaceRawProcessorRegistryInterface $processorRegistry,
        private MarketplaceRawDocumentRepository $repository,
        private MarketplaceSaleRepository $saleRepository,
        private MarketplaceReturnRepository $returnRepository,
        private MarketplaceCostRepository $costRepository,
        private EntityManagerInterface $entityManager,
        private MarketplaceCostCategoryResolver $costCategoryResolver,
        private Connection $connection,
        private AppLogger $appLogger,
    ) {
    }

    public function __invoke(ProcessMarketplaceRawDocumentCommand $command): ProcessRawDocumentResult
    {
        $document = $this->repository->find($command->rawDocId);

        if (null === $document) {
            throw new \RuntimeException(sprintf('Raw document not found: %s', $command->rawDocId));
        }

        // Defense-in-depth: документ обязан принадлежать компании из команды,
        // даже если вызывающий код забыл проверить (IDOR-защита на уровне Action).
        // Unrecoverable: tenant-mismatch детерминирован, ретраи бессмысленны.
        if ((string) $document->getCompany()->getId() !== (string) $command->companyId) {
            throw new UnrecoverableMessageHandlingException('Raw document does not belong to the given company.');
        }

        $kindToBucketKey = [
            'sales' => StagingRecordType::SALE->value,
            'returns' => StagingRecordType::RETURN->value,
            'costs' => StagingRecordType::COST->value,
        ];

        $targetBucketKey = $kindToBucketKey[$command->kind] ?? null;

        if (null === $targetBucketKey) {
            throw new \InvalidArgumentException(sprintf('Unknown kind "%s". Allowed: sales, returns, costs.', $command->kind));
        }

        $marketplace = $document->getMarketplace();

        if ($command->forceReprocess && MarketplaceType::WILDBERRIES === $marketplace) {
            $company = $document->getCompany();

            if ('sales' === $command->kind) {
                $this->saleRepository->deleteByRawDocument($company, $marketplace, $command->rawDocId);
            } elseif ('returns' === $command->kind) {
                $this->returnRepository->deleteByRawDocument($company, $marketplace, $command->rawDocId);
            }
        }

        $this->appLogger->info('ProcessMarketplaceRawDocumentAction called', [
            'rawDocId' => $command->rawDocId,
            'kind' => $command->kind,
            'forceReprocess' => $command->forceReprocess,
        ]);

        // Costs step: use process() directly instead of classifier + processBatch().
        // The classifier sends type=orders rows to SALE bucket, but they contain
        // commissions, delivery charges, and logistics services that are costs.
        // process() reads ALL operations from the raw document and handles them correctly.
        if ('costs' === $command->kind) {
            $linkedRows = $command->forceReprocess && MarketplaceType::WILDBERRIES === $marketplace
                ? $this->costRepository->countDocumentLinkedByRawDocument($document->getCompany(), $marketplace, $command->rawDocId)
                : 0;

            // Delete existing unfiled costs before reprocessing (WB needs this;
            // Ozon's process() also does its own DELETE, the double-delete is a safe no-op).
            $this->connection->executeStatement(
                'DELETE FROM marketplace_costs
                 WHERE raw_document_id = :rawDocId
                   AND document_id IS NULL',
                ['rawDocId' => $command->rawDocId],
            );

            $processor = $this->processorRegistry->get(StagingRecordType::COST, $marketplace);
            $result = $processor->process($command->companyId, $command->rawDocId);
            $this->costCategoryResolver->clearCache();

            return $this->buildResult($command->rawDocId, $command->kind, $result, $linkedRows);
        }

        // --- Sales / Returns path ---

        $buckets = [
            StagingRecordType::SALE->value => [],
            StagingRecordType::RETURN->value => [],
            StagingRecordType::COST->value => [],
            StagingRecordType::OTHER->value => [],
        ];

        $rows = $document->getRawData();
        if (
            MarketplaceType::OZON === $marketplace
            && isset($rows['result']['operations'])
            && is_array($rows['result']['operations'])
        ) {
            $rows = $rows['result']['operations'];
        }

        $classifier = $this->classifierRegistry->get($marketplace);

        $linkedRows = 0;
        if ($command->forceReprocess && MarketplaceType::WILDBERRIES === $marketplace) {
            $linkedRows = $this->cleanupWbOpenRowsByExternalIds(
                company: $document->getCompany(),
                marketplace: $marketplace,
                kind: $command->kind,
                targetBucketKey: $targetBucketKey,
                rows: $rows,
                classifier: $classifier,
                rawDocId: $command->rawDocId,
            );
        }

        $totalProcessed = 0;

        // Reset per-run processor state: one raw document can be split into multiple
        // batches in this invocation, but reprocessing the same rawDocId in another
        // invocation must run cleanup again (idempotent replace-by-raw-document).
        $processor = $this->processorRegistry->get(StagingRecordType::from($targetBucketKey), $marketplace);
        if (method_exists($processor, 'resetPerRunState')) {
            $processor->resetPerRunState();
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = $classifier->classify($row);
            $bucketKey = $type->value;
            $buckets[$bucketKey][] = $row;

            if (count($buckets[$bucketKey]) >= 500) {
                if ($bucketKey === $targetBucketKey) {
                    $processor->processBatch(
                        $command->companyId,
                        $marketplace,
                        $buckets[$bucketKey],
                        $command->rawDocId,
                    );
                    $totalProcessed += count($buckets[$bucketKey]);
                    $this->entityManager->clear();
                    $this->costCategoryResolver->resetCache();
                }

                $buckets[$bucketKey] = [];
            }
        }

        foreach ($buckets as $bucketKey => $bucketRows) {
            if ($bucketKey !== $targetBucketKey) {
                continue;
            }

            if ([] === $bucketRows) {
                continue;
            }

            $processor->processBatch(
                $command->companyId,
                $marketplace,
                $bucketRows,
                $command->rawDocId,
            );
            $totalProcessed += count($bucketRows);
            $this->entityManager->clear();
            $this->costCategoryResolver->resetCache();
        }

        $this->costCategoryResolver->clearCache();

        return $this->buildResult($command->rawDocId, $command->kind, $totalProcessed, $linkedRows);
    }

    /**
     * @param array<int|string, mixed> $rows
     */
    private function cleanupWbOpenRowsByExternalIds(
        Company $company,
        MarketplaceType $marketplace,
        string $kind,
        string $targetBucketKey,
        array $rows,
        RowClassifierInterface $classifier,
        string $rawDocId,
    ): int {
        if (!in_array($kind, ['sales', 'returns'], true)) {
            return 0;
        }

        $externalIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = $classifier->classify($row);
            if ($type->value !== $targetBucketKey) {
                continue;
            }

            $srid = trim((string) ($row['srid'] ?? ''));
            if ('' !== $srid) {
                $externalIds[] = $srid;
            }
        }

        $externalIds = array_values(array_unique($externalIds));

        if ('sales' === $kind) {
            $linkedRows = $this->saleRepository->countDocumentLinkedByRawDocument($company, $marketplace, $rawDocId);
            $this->saleRepository->deleteOpenByExternalIds($company, $marketplace, $externalIds);

            return $linkedRows;
        }

        $linkedRows = $this->returnRepository->countDocumentLinkedByRawDocument($company, $marketplace, $rawDocId);
        $this->returnRepository->deleteOpenByExternalIds($company, $marketplace, $externalIds);

        return $linkedRows;
    }

    private function buildResult(string $rawDocId, string $kind, int $processedRows, int $linkedRows): ProcessRawDocumentResult
    {
        if ($linkedRows > 0) {
            // Ожидаемый штатный исход: строки закрытого документа не перезаписываются.
            // warning, а не error — будить человека нечем, ремонта не требуется.
            $this->appLogger->warning('WB raw document partially reprocessed; linked rows preserved', [
                'rawDocId' => $rawDocId,
                'kind' => $kind,
                'processedRows' => $processedRows,
                'preservedLinkedRows' => $linkedRows,
            ]);
        }

        return new ProcessRawDocumentResult($processedRows, $linkedRows);
    }
}
