<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Application\DTO\OzonRawDuplicatesCleanupExecutionResult;
use App\Marketplace\Application\DTO\OzonRawDuplicatesCleanupPlan;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class OzonRawDuplicatesCleanupExecutor
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function execute(OzonRawDuplicatesCleanupPlan $plan): OzonRawDuplicatesCleanupExecutionResult
    {
        $deletedSalesRows = 0;
        $deletedReturnsRows = 0;
        $deletedCostsRows = 0;
        $cleanedDays = 0;
        $canonicalRawDocumentIds = [];

        $this->connection->beginTransaction();

        try {
            foreach ($plan->affectedDays as $dayPlan) {
                if (!$dayPlan->canAutoCleanup) {
                    continue;
                }

                $deletedSalesRows += $this->deleteStaleRows('marketplace_sales', 'sale_date', $plan->companyId, $dayPlan->day, $dayPlan->canonicalRawDocumentId);
                $deletedReturnsRows += $this->deleteStaleRows('marketplace_returns', 'return_date', $plan->companyId, $dayPlan->day, $dayPlan->canonicalRawDocumentId);
                $deletedCostsRows += $this->deleteStaleRows('marketplace_costs', 'cost_date', $plan->companyId, $dayPlan->day, $dayPlan->canonicalRawDocumentId);
                ++$cleanedDays;
                $canonicalRawDocumentIds[] = $dayPlan->canonicalRawDocumentId;
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            $this->logger->error('Ozon raw duplicates cleanup rolled back.', ['exception' => $e]);

            throw $e;
        }

        $this->logger->info('Ozon raw duplicates cleanup applied.', [
            'companyId' => $plan->companyId,
            'from' => $plan->from->format('Y-m-d'),
            'to' => $plan->to->format('Y-m-d'),
            'cleanedDays' => $cleanedDays,
            'deletedSalesRows' => $deletedSalesRows,
            'deletedReturnsRows' => $deletedReturnsRows,
            'deletedCostsRows' => $deletedCostsRows,
        ]);

        return new OzonRawDuplicatesCleanupExecutionResult(
            deletedSalesRows: $deletedSalesRows,
            deletedReturnsRows: $deletedReturnsRows,
            deletedCostsRows: $deletedCostsRows,
            cleanedDaysCount: $cleanedDays,
            cleanedCanonicalRawDocumentIds: array_values(array_unique($canonicalRawDocumentIds)),
        );
    }

    private function deleteStaleRows(string $table, string $dateField, string $companyId, \DateTimeImmutable $day, string $canonicalRawDocumentId): int
    {
        return $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s t WHERE t.company_id = :companyId AND t.marketplace = :marketplace AND t.%s = :day AND t.raw_document_id IS NOT NULL AND t.raw_document_id <> :canonicalRawDocumentId AND t.document_id IS NULL',
                $table,
                $dateField,
            ),
            [
                'companyId' => $companyId,
                'marketplace' => 'ozon',
                'day' => $day->format('Y-m-d'),
                'canonicalRawDocumentId' => $canonicalRawDocumentId,
            ],
        );
    }
}
