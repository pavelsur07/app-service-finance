<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Application\DTO\OzonRawDuplicatesCleanupDayPlan;
use App\Marketplace\Application\DTO\OzonRawDuplicatesCleanupPlan;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

final readonly class OzonRawDuplicatesCleanupPlanner
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function buildPlan(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): OzonRawDuplicatesCleanupPlan
    {
        Assert::uuid($companyId);

        $days = $this->findAffectedDays($companyId, $from, $to);
        $dayPlans = [];

        foreach ($days as $day) {
            $rawDocuments = $this->findRawDocumentsForDay($companyId, $day);
            if ($rawDocuments === []) {
                continue;
            }

            $canonical = $this->chooseCanonical($rawDocuments);
            $duplicateIds = array_values(array_filter(
                array_map(static fn (array $row): string => (string) $row['id'], $rawDocuments),
                static fn (string $id): bool => $id !== $canonical,
            ));

            $staleSales = $this->countStaleRows('marketplace_sales', 'sale_date', $companyId, $day, $canonical, false);
            $staleReturns = $this->countStaleRows('marketplace_returns', 'return_date', $companyId, $day, $canonical, false);
            $staleCosts = $this->countStaleRows('marketplace_costs', 'cost_date', $companyId, $day, $canonical, false);
            $closedSales = $this->countStaleRows('marketplace_sales', 'sale_date', $companyId, $day, $canonical, true);
            $closedReturns = $this->countStaleRows('marketplace_returns', 'return_date', $companyId, $day, $canonical, true);
            $closedCosts = $this->countStaleRows('marketplace_costs', 'cost_date', $companyId, $day, $canonical, true);

            $warnings = [];
            $hasPendingOrRunning = $this->hasPendingOrRunningRawDocument($rawDocuments);

            if ($closedSales > 0 || $closedReturns > 0 || $closedCosts > 0) {
                $warnings[] = 'Найдены closed rows (document_id IS NOT NULL), auto-cleanup отключён для этого дня.';
            }
            if ($hasPendingOrRunning) {
                $warnings[] = 'Есть PENDING/RUNNING rawDoc, auto-cleanup запрещён до завершения pipeline.';
            }

            if ($staleSales + $staleReturns + $staleCosts === 0) {
                $warnings[] = 'Stale open rows не найдены, очистка processed-таблиц для дня не требуется.';
            }

            $safeToDeleteRawDocs = $this->findSafeToDeleteRawDocuments($duplicateIds);

            $dayPlans[] = new OzonRawDuplicatesCleanupDayPlan(
                day: $day,
                canonicalRawDocumentId: $canonical,
                duplicateRawDocumentIds: $duplicateIds,
                staleSalesRowsCount: $staleSales,
                staleReturnsRowsCount: $staleReturns,
                staleCostsRowsCount: $staleCosts,
                closedSalesRowsCount: $closedSales,
                closedReturnsRowsCount: $closedReturns,
                closedCostsRowsCount: $closedCosts,
                canAutoCleanup: !$hasPendingOrRunning && $closedSales === 0 && $closedReturns === 0 && $closedCosts === 0,
                safeToDeleteRawDocumentIds: $safeToDeleteRawDocs,
                warnings: $warnings,
            );
        }

        return new OzonRawDuplicatesCleanupPlan($companyId, $from, $to, $dayPlans);
    }

    /** @return list<\DateTimeImmutable> */
    private function findAffectedDays(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchFirstColumn(
            <<<SQL
            WITH duplicate_processed_days AS (
                SELECT s.sale_date::date AS day
                FROM marketplace_sales s
                WHERE s.company_id = :companyId
                  AND s.marketplace = :marketplace
                  AND s.sale_date BETWEEN :fromDate AND :toDate
                  AND s.raw_document_id IS NOT NULL
                GROUP BY s.sale_date::date
                HAVING COUNT(DISTINCT s.raw_document_id) > 1
                UNION
                SELECT r.return_date::date AS day
                FROM marketplace_returns r
                WHERE r.company_id = :companyId
                  AND r.marketplace = :marketplace
                  AND r.return_date BETWEEN :fromDate AND :toDate
                  AND r.raw_document_id IS NOT NULL
                GROUP BY r.return_date::date
                HAVING COUNT(DISTINCT r.raw_document_id) > 1
                UNION
                SELECT c.cost_date::date AS day
                FROM marketplace_costs c
                WHERE c.company_id = :companyId
                  AND c.marketplace = :marketplace
                  AND c.cost_date BETWEEN :fromDate AND :toDate
                  AND c.raw_document_id IS NOT NULL
                GROUP BY c.cost_date::date
                HAVING COUNT(DISTINCT c.raw_document_id) > 1
            ),
            duplicate_raw_days AS (
                SELECT rd.period_from::date AS day
                FROM marketplace_raw_documents rd
                WHERE rd.company_id = :companyId
                  AND rd.marketplace = :marketplace
                  AND rd.document_type = 'sales_report'
                  AND rd.period_from BETWEEN :fromDate AND :toDate
                  AND rd.period_to BETWEEN :fromDate AND :toDate
                GROUP BY rd.period_from::date, rd.period_to::date
                HAVING COUNT(*) > 1
                UNION
                SELECT daily.period_from::date AS day
                FROM marketplace_raw_documents daily
                INNER JOIN marketplace_raw_documents range_doc
                    ON range_doc.company_id = daily.company_id
                   AND range_doc.marketplace = daily.marketplace
                   AND range_doc.document_type = daily.document_type
                   AND range_doc.id <> daily.id
                   AND range_doc.period_from <= daily.period_from
                   AND range_doc.period_to >= daily.period_to
                   AND range_doc.period_from <> range_doc.period_to
                WHERE daily.company_id = :companyId
                  AND daily.marketplace = :marketplace
                  AND daily.document_type = 'sales_report'
                  AND daily.period_from = daily.period_to
                  AND daily.period_from BETWEEN :fromDate AND :toDate
            ),
            legacy_processed_days AS (
                SELECT s.sale_date::date AS day
                FROM marketplace_sales s
                WHERE s.company_id = :companyId
                  AND s.marketplace = :marketplace
                  AND s.sale_date BETWEEN :fromDate AND :toDate
                  AND s.document_id IS NULL
                  AND s.raw_document_id IS NULL
                  AND EXISTS (
                      SELECT 1
                      FROM marketplace_raw_documents rd
                      WHERE rd.company_id = :companyId
                        AND rd.marketplace = :marketplace
                        AND rd.document_type = 'sales_report'
                        AND rd.period_from <= s.sale_date::date
                        AND rd.period_to >= s.sale_date::date
                  )
                UNION
                SELECT r.return_date::date AS day
                FROM marketplace_returns r
                WHERE r.company_id = :companyId
                  AND r.marketplace = :marketplace
                  AND r.return_date BETWEEN :fromDate AND :toDate
                  AND r.document_id IS NULL
                  AND r.raw_document_id IS NULL
                  AND EXISTS (
                      SELECT 1
                      FROM marketplace_raw_documents rd
                      WHERE rd.company_id = :companyId
                        AND rd.marketplace = :marketplace
                        AND rd.document_type = 'sales_report'
                        AND rd.period_from <= r.return_date::date
                        AND rd.period_to >= r.return_date::date
                  )
                UNION
                SELECT c.cost_date::date AS day
                FROM marketplace_costs c
                WHERE c.company_id = :companyId
                  AND c.marketplace = :marketplace
                  AND c.cost_date BETWEEN :fromDate AND :toDate
                  AND c.document_id IS NULL
                  AND c.raw_document_id IS NULL
                  AND EXISTS (
                      SELECT 1
                      FROM marketplace_raw_documents rd
                      WHERE rd.company_id = :companyId
                        AND rd.marketplace = :marketplace
                        AND rd.document_type = 'sales_report'
                        AND rd.period_from <= c.cost_date::date
                        AND rd.period_to >= c.cost_date::date
                  )
            )
            SELECT DISTINCT day
            FROM (
                SELECT day FROM duplicate_processed_days
                UNION
                SELECT day FROM duplicate_raw_days
                UNION
                SELECT day FROM legacy_processed_days
            ) all_days
            ORDER BY day ASC
            SQL,
            [
                'companyId' => $companyId,
                'fromDate' => $from->format('Y-m-d'),
                'toDate' => $to->format('Y-m-d'),
                'marketplace' => MarketplaceType::OZON->value,
            ],
        );

        return array_map(
            static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day),
            array_values($rows),
        );
    }

    /** @return list<array{id:string, period_from:string, period_to:string, processing_status:?string, synced_at:string, records_count:int}> */
    private function findRawDocumentsForDay(string $companyId, \DateTimeImmutable $day): array
    {
        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT rd.id, rd.period_from, rd.period_to, rd.processing_status, rd.synced_at, rd.records_count
            FROM marketplace_raw_documents rd
            WHERE rd.company_id = :companyId
              AND rd.marketplace = :marketplace
              AND rd.document_type = 'sales_report'
              AND rd.period_from <= :day
              AND rd.period_to >= :day
            SQL,
            [
                'companyId' => $companyId,
                'day' => $day->format('Y-m-d'),
                'marketplace' => MarketplaceType::OZON->value,
            ],
        );
    }

    private function chooseCanonical(array $rawDocuments): string
    {
        usort($rawDocuments, static function (array $left, array $right): int {
            $leftDaily = $left['period_from'] === $left['period_to'];
            $rightDaily = $right['period_from'] === $right['period_to'];
            if ($leftDaily !== $rightDaily) {
                return $leftDaily ? -1 : 1;
            }

            $statusRank = static function (?string $status): int {
                return match ($status) {
                    'completed' => 3,
                    null => 2,
                    'failed' => 1,
                    'pending', 'running' => 0,
                    default => 1,
                };
            };

            $statusCmp = $statusRank($right['processing_status']) <=> $statusRank($left['processing_status']);
            if ($statusCmp !== 0) {
                return $statusCmp;
            }

            $syncedCmp = strtotime((string) $right['synced_at']) <=> strtotime((string) $left['synced_at']);
            if ($syncedCmp !== 0) {
                return $syncedCmp;
            }

            $recordsCmp = ((int) $right['records_count']) <=> ((int) $left['records_count']);
            if ($recordsCmp !== 0) {
                return $recordsCmp;
            }

            return strcmp((string) $left['id'], (string) $right['id']);
        });

        return (string) $rawDocuments[0]['id'];
    }

    private function countStaleRows(string $table, string $dateField, string $companyId, \DateTimeImmutable $day, string $canonicalRawDocumentId, bool $closed): int
    {
        return (int) $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM %s t WHERE t.company_id = :companyId AND t.marketplace = :marketplace AND t.%s = :day AND %s',
                $table,
                $dateField,
                $closed
                    ? 't.document_id IS NOT NULL AND t.raw_document_id IS NOT NULL AND t.raw_document_id <> :canonicalRawDocumentId'
                    : 't.document_id IS NULL AND (t.raw_document_id IS NULL OR t.raw_document_id <> :canonicalRawDocumentId)',
            ),
            [
                'companyId' => $companyId,
                'marketplace' => MarketplaceType::OZON->value,
                'day' => $day->format('Y-m-d'),
                'canonicalRawDocumentId' => $canonicalRawDocumentId,
            ],
        );
    }

    private function hasPendingOrRunningRawDocument(array $rawDocuments): bool
    {
        foreach ($rawDocuments as $rawDocument) {
            if (in_array($rawDocument['processing_status'], ['pending', 'running'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $rawDocumentIds */
    private function findSafeToDeleteRawDocuments(array $rawDocumentIds): array
    {
        if ($rawDocumentIds === []) {
            return [];
        }

        $safe = [];
        foreach ($rawDocumentIds as $rawDocumentId) {
            $references = (int) $this->connection->fetchOne(
                <<<SQL
                SELECT
                    (SELECT COUNT(*) FROM marketplace_sales s WHERE s.raw_document_id = :rawDocumentId)
                  + (SELECT COUNT(*) FROM marketplace_returns r WHERE r.raw_document_id = :rawDocumentId)
                  + (SELECT COUNT(*) FROM marketplace_costs c WHERE c.raw_document_id = :rawDocumentId)
                SQL,
                ['rawDocumentId' => $rawDocumentId],
            );

            if ($references === 0) {
                $safe[] = $rawDocumentId;
            }
        }

        return $safe;
    }
}
