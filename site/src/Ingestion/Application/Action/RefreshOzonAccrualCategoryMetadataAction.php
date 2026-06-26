<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RefreshOzonAccrualCategoryMetadataAction
{
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
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $externalWindowFrom = "substring(r.external_id from '^accrual-by-day:([0-9]{4}-[0-9]{2}-[0-9]{2}):[0-9]{4}-[0-9]{2}-[0-9]{2}$')::date";
        $externalWindowTo = "substring(r.external_id from '^accrual-by-day:[0-9]{4}-[0-9]{2}-[0-9]{2}:([0-9]{4}-[0-9]{2}-[0-9]{2})$')::date";
        $windowFrom = sprintf('COALESCE(j.window_from, %s, DATE(r.fetched_at))', $externalWindowFrom);
        $windowTo = sprintf('COALESCE(j.window_to, j.window_from, %s, %s, DATE(r.fetched_at))', $externalWindowTo, $externalWindowFrom);
        $conditions = [
            'r.company_id = :companyId',
            'r.source = :source',
            'r.resource_type = :resourceType',
            'r.normalization_status = :status',
            sprintf('%s <= :toDate', $windowFrom),
            sprintf('%s >= :fromDate', $windowTo),
        ];
        $params = [
            'companyId' => $companyId,
            'source' => IngestSource::OZON->value,
            'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            'status' => RawNormalizationStatus::DONE->value,
            'fromDate' => $from->format('Y-m-d'),
            'toDate' => $to->format('Y-m-d'),
        ];

        if (null !== $shopRef && '' !== $shopRef) {
            $conditions[] = 'r.shop_ref = :shopRef';
            $params['shopRef'] = $shopRef;
        }

        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT r.id,
                        r.external_id,
                        r.shop_ref,
                        r.fetched_at,
                        r.byte_size,
                        r.normalization_status,
                        TO_CHAR(%s, \'YYYY-MM-DD\') AS window_from,
                        TO_CHAR(%s, \'YYYY-MM-DD\') AS window_to
                 FROM ingest_raw_records r
                 LEFT JOIN ingest_sync_jobs j ON j.id::text = r.sync_job_id AND j.company_id = r.company_id
                 WHERE %s
                 ORDER BY %s ASC, %s ASC, r.fetched_at ASC, r.created_at ASC
                 LIMIT %d
                 OFFSET %d',
                $windowFrom,
                $windowTo,
                implode(' AND ', $conditions),
                $windowFrom,
                $windowTo,
                $limit,
                $offset,
            ),
            $params,
        );
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
                $existingByKey = $this->existingTransactionsByNaturalKey($companyId, $rawRecord);

                $scanned = 0;
                $matched = 0;
                $updated = 0;
                $unchanged = 0;
                $missing = 0;

                foreach ($mappedTransactions as $mappedTransaction) {
                    ++$scanned;
                    $transaction = $existingByKey[$this->naturalKey($mappedTransaction)] ?? null;
                    if (!$transaction instanceof FinancialTransaction) {
                        $transaction = $this->financialTransactionRepository->findByNaturalKey(
                            $companyId,
                            IngestSource::OZON,
                            $mappedTransaction->externalId,
                            $mappedTransaction->type,
                        );
                    }

                    if (!$transaction instanceof FinancialTransaction) {
                        ++$missing;
                        continue;
                    }

                    ++$matched;
                    if (!$this->metadataDiffers($transaction, $mappedTransaction)) {
                        ++$unchanged;
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
                }

                if (!$dryRun) {
                    $this->entityManager->flush();
                    $this->connection->commit();
                }
                $this->entityManager->clear();

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
                $this->entityManager->clear();

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

    /**
     * @return array<string, FinancialTransaction>
     */
    private function existingTransactionsByNaturalKey(string $companyId, IngestRawRecord $rawRecord): array
    {
        $transactions = [];
        foreach ($this->financialTransactionRepository->findByRawRecordId($companyId, $rawRecord->getId()) as $transaction) {
            $transactions[sprintf('%s:%s', $transaction->getExternalId(), $transaction->getType()->value)] = $transaction;
        }

        return $transactions;
    }

    private function naturalKey(MappedTransaction $transaction): string
    {
        return sprintf('%s:%s', $transaction->externalId, $transaction->type->value);
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
