<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\EnsureWbFinanceCursorCommand;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\IngestCursorRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;

final readonly class EnsureWbFinanceCursorAction
{
    public function __construct(
        private IngestCursorRepository $cursorRepository,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EnsureWbFinanceCursorCommand $command): void
    {
        if ([] !== $this->cursorRepository->findByResource($command->companyId, $command->connectionRef, WbResourceType::FINANCE_SALES_REPORT_DETAILED)) {
            return;
        }

        $cursor = $this->cursorRepository->getOrCreate(
            $command->companyId,
            $command->connectionRef,
            WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            $command->connectionRef,
        );
        $cursor->advance($this->seedCursorValue($command), Uuid::uuid7()->toString());
        $this->entityManager->flush();
    }

    private function seedCursorValue(EnsureWbFinanceCursorCommand $command): string
    {
        $latestRawDate = $this->latestRawReportDate($command);
        if (null !== $latestRawDate) {
            return $latestRawDate->modify('+1 day')->format('Y-m-d');
        }

        return $this->clock->now()->modify('first day of this month')->format('Y-m-d');
    }

    private function latestRawReportDate(EnsureWbFinanceCursorCommand $command): ?\DateTimeImmutable
    {
        // WbFinanceReportConnector stores raw pages as wb-sales-report-detailed:YYYY-MM-DD:rrd-N.
        $value = $this->connection->fetchOne(
            "SELECT MAX(substring(external_id from '^wb-sales-report-detailed:([0-9]{4}-[0-9]{2}-[0-9]{2}):rrd-[0-9]+$')::date)
             FROM ingest_raw_records
             WHERE company_id = :companyId
               AND connection_ref = :connectionRef
               AND shop_ref = :shopRef
               AND source = :source
               AND resource_type = :resourceType
               AND external_id ~ '^wb-sales-report-detailed:[0-9]{4}-[0-9]{2}-[0-9]{2}:rrd-[0-9]+$'",
            [
                'companyId' => $command->companyId,
                'connectionRef' => $command->connectionRef,
                'shopRef' => $command->connectionRef,
                'source' => IngestSource::WILDBERRIES->value,
                'resourceType' => WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            ],
        );

        if (!is_string($value) || '' === $value) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }
}
