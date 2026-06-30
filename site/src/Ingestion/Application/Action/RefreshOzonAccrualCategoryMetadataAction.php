<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Infrastructure\Query\OzonAccrualRawRecordQuery;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RefreshOzonAccrualCategoryMetadataAction
{
    private const FLUSH_BATCH_SIZE = 500;

    private const CATEGORY_SOURCE_DATA_KEYS = [
        '_ingestion_external_code',
        '_ingestion_provider_label',
        '_ozon_category_code',
        '_ozon_category_label',
        '_ozon_category_group',
        '_ozon_category_parent',
        '_ozon_category_sort_order',
        '_ozon_category_known',
        'external_code',
        'provider_label',
    ];

    public function __construct(
        private Connection $connection,
        private IngestRawRecordRepository $rawRecordRepository,
        private FinancialTransactionRepository $financialTransactionRepository,
        private RawStorageFacade $rawStorageFacade,
        private OzonAccrualByDayMapper $mapper,
        private EntityManagerInterface $entityManager,
        private OzonAccrualRawRecordQuery $rawRecordQuery,
    ) {
    }

    /**
     * @return array{rawRecords: list<array<string, mixed>>, results: list<array<string, string|int>>}
     */
    public function __invoke(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $shopRef,
        int $limit,
        bool $dryRun,
    ): array {
        $rawRecords = $this->rawRecords($companyId, $from, $to, $shopRef, $limit);

        return [
            'rawRecords' => $rawRecords,
            'results' => $this->refresh($companyId, $rawRecords, $dryRun),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rawRecords(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $shopRef,
        int $limit,
        int $offset = 0,
    ): array {
        return $this->rawRecordQuery->latestCoverageRows(
            $companyId,
            $shopRef,
            $from,
            $to,
            max(1, min(500, $limit)),
            [RawNormalizationStatus::DONE->value],
            max(0, $offset),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rawRecord(string $companyId, string $rawRecordId): ?array
    {
        return $this->rawRecordQuery->doneRawRecord($companyId, $rawRecordId);
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     *
     * @return list<array<string, string|int>>
     */
    public function refresh(string $companyId, array $rawRecords, bool $dryRun): array
    {
        $resultRows = [];

        foreach ($rawRecords as $row) {
            $rawRecordId = (string) $row['id'];

            if (!$dryRun) {
                $this->connection->beginTransaction();
            }

            try {
                $rawRecord = $this->rawRecordRepository->findByIdAndCompany($rawRecordId, $companyId);
                if (null === $rawRecord || RawNormalizationStatus::DONE !== $rawRecord->getNormalizationStatus()) {
                    throw new \RuntimeException('Done Ozon accrual raw record was not found.');
                }

                /** @var list<array<string, mixed>> $rows */
                $rows = array_values(iterator_to_array($this->rawStorageFacade->read($rawRecord->getId(), $companyId), false));
                $mappedTransactions = $this->mapper->mapForCategoryMetadataRefresh(
                    rawRecord: $rawRecord,
                    rows: $rows,
                    recordUnknownCategories: !$dryRun,
                );
                unset($rows);

                $scanned = 0;
                $matched = 0;
                $updated = 0;
                $unchanged = 0;
                $missing = 0;

                foreach ($mappedTransactions as $mappedTransaction) {
                    ++$scanned;
                    $transaction = $this->financialTransactionRepository->findByNaturalKey(
                        $companyId,
                        IngestSource::OZON,
                        $mappedTransaction->externalId,
                        $mappedTransaction->type,
                    );

                    if (!$transaction instanceof FinancialTransaction) {
                        ++$missing;
                        $this->releaseBatchIfNeeded($dryRun, $scanned);
                        continue;
                    }

                    ++$matched;
                    if (!$this->metadataDiffers($transaction, $mappedTransaction)) {
                        ++$unchanged;
                        $this->releaseBatchIfNeeded($dryRun, $scanned);
                        continue;
                    }

                    ++$updated;
                    if (!$dryRun) {
                        $transaction->replaceSourceDataFields(
                            $mappedTransaction->sourceData,
                            self::CATEGORY_SOURCE_DATA_KEYS,
                            $mappedTransaction->description,
                        );
                    }
                    $this->releaseBatchIfNeeded($dryRun, $scanned);
                }
                unset($mappedTransactions);

                if (!$dryRun) {
                    $this->entityManager->flush();
                    $this->connection->commit();
                }
                $this->clearManagedState();

                $resultRows[] = [
                    'rawId' => $rawRecordId,
                    'status' => $dryRun ? 'dry-run' : 'done',
                    'scanned' => $scanned,
                    'matched' => $matched,
                    'updated' => $updated,
                    'unchanged' => $unchanged,
                    'missing' => $missing,
                ];
            } catch (\Throwable $exception) {
                if (!$dryRun) {
                    if ($this->connection->isTransactionActive()) {
                        $this->connection->rollBack();
                    }
                }
                $this->clearManagedState();

                $resultRows[] = [
                    'rawId' => $rawRecordId,
                    'status' => 'error',
                    'scanned' => 0,
                    'matched' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'missing' => 0,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $resultRows;
    }

    private function releaseBatchIfNeeded(bool $dryRun, int $scanned): void
    {
        if (0 !== $scanned % self::FLUSH_BATCH_SIZE) {
            return;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $this->clearManagedState();
    }

    private function clearManagedState(): void
    {
        $this->entityManager->clear();
        $this->financialTransactionRepository->reset();
    }

    private function metadataDiffers(FinancialTransaction $transaction, MappedTransaction $mappedTransaction): bool
    {
        $existing = $transaction->getSourceData();
        foreach (self::CATEGORY_SOURCE_DATA_KEYS as $key) {
            if (!array_key_exists($key, $mappedTransaction->sourceData)) {
                continue;
            }

            if (!array_key_exists($key, $existing) || $existing[$key] !== $mappedTransaction->sourceData[$key]) {
                return true;
            }
        }

        return null !== $mappedTransaction->description && $transaction->getDescription() !== $mappedTransaction->description;
    }
}
