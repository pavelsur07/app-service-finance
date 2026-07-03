<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\FinancialTransactionRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class OzonAccrualStaleProjectionPruner
{
    public function __construct(
        private Connection $connection,
        private FinancialTransactionRepository $transactionRepository,
    ) {
    }

    /**
     * @param list<MappedTransaction> $mappedTransactions
     */
    public function prune(IngestRawRecord $rawRecord, array $mappedTransactions, bool $execute, bool $includeRows = false): OzonAccrualStaleProjectionPruneResult
    {
        if (IngestSource::OZON !== $rawRecord->getSource() || OzonResourceType::ACCRUAL_BY_DAY !== $rawRecord->getResourceType()) {
            return new OzonAccrualStaleProjectionPruneResult(0, 0, []);
        }

        $window = $this->rawWindow($rawRecord->getExternalId());
        if (null === $window) {
            return new OzonAccrualStaleProjectionPruneResult(0, 0, []);
        }

        $expected = [];
        foreach ($mappedTransactions as $transaction) {
            $expected[$this->key($transaction->externalId, $transaction->type->value)] = true;
        }

        [$from, $to] = $window;
        $candidateRows = $this->candidateRows($rawRecord, $from, $to);
        $staleRows = [];
        $deleteIds = [];
        $affectedDates = [];

        foreach ($candidateRows as $row) {
            if (isset($expected[$this->key((string) $row['external_id'], (string) $row['type'])])) {
                continue;
            }

            $date = (string) $row['date'];
            $affectedDates[$date] = true;
            $deleteIds[] = (string) $row['id'];
            if ($includeRows) {
                $staleRows[] = $row;
            }
        }

        $deleted = 0;
        if ($execute && [] !== $deleteIds) {
            foreach (array_chunk($deleteIds, 1000) as $chunk) {
                $deleted += (int) $this->connection->executeStatement(
                    'DELETE FROM ingest_financial_transactions
                     WHERE company_id = :companyId
                       AND id IN (:ids)',
                    [
                        'companyId' => $rawRecord->getCompanyId(),
                        'ids' => $chunk,
                    ],
                    [
                        'ids' => ArrayParameterType::STRING,
                    ],
                );
            }

            $this->transactionRepository->reset();
        }

        $dates = array_keys($affectedDates);
        sort($dates);

        return new OzonAccrualStaleProjectionPruneResult(count($deleteIds), $deleted, $dates, $staleRows);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function rawWindow(string $externalId): ?array
    {
        if (1 !== preg_match('/^accrual-by-day:(\d{4}-\d{2}-\d{2}):(\d{4}-\d{2}-\d{2})$/', $externalId, $matches)) {
            return null;
        }

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $matches[2]);
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable || $from > $to) {
            return null;
        }

        return [$from, $to];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidateRows(IngestRawRecord $rawRecord, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT
                ft.id,
                DATE(ft.occurred_at) AS date,
                ft.external_id,
                ft.type,
                ft.direction,
                ft.amount_minor,
                old_raw.external_id AS stale_raw_external_id,
                old_raw.fetched_at AS stale_raw_fetched_at
             FROM ingest_financial_transactions ft
             JOIN ingest_raw_records old_raw
               ON old_raw.id = ft.raw_record_id
              AND old_raw.company_id = ft.company_id
             WHERE ft.company_id = :companyId
               AND ft.shop_ref = :shopRef
               AND ft.source = :source
               AND ft.raw_record_id <> :rawRecordId
               AND ft.occurred_at >= :fromAt
               AND ft.occurred_at < :toExclusive
               AND old_raw.source = :source
               AND old_raw.resource_type = :resourceType
               AND old_raw.fetched_at < :fetchedAt
               AND (
                    ft.source_data->>'_ingestion_resource' = :resourceType
                    OR ft.external_id LIKE :externalIdPrefix
               )
             ORDER BY DATE(ft.occurred_at), old_raw.fetched_at, ft.external_id, ft.type",
            [
                'companyId' => $rawRecord->getCompanyId(),
                'shopRef' => $rawRecord->getShopRef(),
                'source' => IngestSource::OZON->value,
                'rawRecordId' => $rawRecord->getId(),
                'fromAt' => $from->format('Y-m-d 00:00:00'),
                'toExclusive' => $to->modify('+1 day')->format('Y-m-d 00:00:00'),
                'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
                'fetchedAt' => $rawRecord->getFetchedAt()->format('Y-m-d H:i:s.u'),
                'externalIdPrefix' => 'ozon:accrual-by-day:%',
            ],
        );
    }

    private function key(string $externalId, string $type): string
    {
        return $externalId."\0".$type;
    }
}
