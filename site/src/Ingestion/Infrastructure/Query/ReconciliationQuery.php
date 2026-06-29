<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\DTO\ReconciliationByTypeView;
use App\Ingestion\Application\DTO\ReconciliationSummaryView;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use App\Marketplace\Repository\OzonTransactionTotalsCheckRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Webmozart\Assert\Assert;

final class ReconciliationQuery
{
    private const THRESHOLD_MINOR = 100;

    public function __construct(
        private readonly Connection $connection,
        private readonly OzonTransactionTotalsCheckRepository $totalsCheckRepository,
    ) {
    }

    public function summary(string $companyId, ?string $shopRef, int $year, int $month): ReconciliationSummaryView
    {
        Assert::uuid($companyId);

        [$from, $toExclusive] = $this->monthBounds($year, $month);

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select(
                'COALESCE(SUM(ft.amount_minor), 0) AS canon_total_minor',
                "COALESCE(MIN(ft.currency), 'RUB') AS currency",
                'MAX(ft.updated_at) AS recomputed_at',
            )
            ->from('ingest_financial_transactions', 'ft')
            ->where('ft.company_id = :companyId')
            ->andWhere('ft.source = :source')
            ->andWhere("(ft.external_id LIKE :externalIdPrefix OR ft.source_data->>'_ingestion_resource' = :resourceType)")
            ->andWhere('ft.occurred_at >= :from')
            ->andWhere('ft.occurred_at < :toExclusive')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', IngestSource::OZON->value)
            ->setParameter('externalIdPrefix', 'ozon:accrual-by-day:%')
            ->setParameter('resourceType', OzonResourceType::ACCRUAL_BY_DAY)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE);

        if (null !== $shopRef && '' !== trim($shopRef)) {
            $queryBuilder
                ->andWhere('ft.shop_ref = :shopRef')
                ->setParameter('shopRef', trim($shopRef));
        }

        $row = $queryBuilder->executeQuery()->fetchAssociative();

        $canonTotalMinor = (int) ($row['canon_total_minor'] ?? 0);
        $currency = (string) ($row['currency'] ?? 'RUB');

        $control = $this->totalsCheckRepository->findLatestByCompanyAndPeriod(
            $companyId,
            $from,
            $toExclusive->modify('-1 day'),
        );
        $ozonTotalMinor = $this->extractOzonTotalMinor($control?->getOzonTotals());
        $delta = null === $ozonTotalMinor ? null : $canonTotalMinor - $ozonTotalMinor;

        return new ReconciliationSummaryView(
            period: sprintf('%04d-%02d', $year, $month),
            canonTotalMinor: $canonTotalMinor,
            ozonControlTotalMinor: $ozonTotalMinor,
            currency: $currency,
            canonVsOzonDeltaMinor: $delta,
            thresholdMinor: self::THRESHOLD_MINOR,
            recomputedAt: $this->latestDateTime($row['recomputed_at'] ?? null, $control?->getCheckedAt()),
        );
    }

    /**
     * @return list<ReconciliationByTypeView>
     */
    public function breakdownByType(string $companyId, ?string $shopRef, int $year, int $month): array
    {
        Assert::uuid($companyId);

        [$from, $toExclusive] = $this->monthBounds($year, $month);

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select(
                'ft.type',
                'COALESCE(SUM(ft.amount_minor), 0) AS canon_amount_minor',
                'COUNT(ft.id) AS tx_count',
            )
            ->from('ingest_financial_transactions', 'ft')
            ->where('ft.company_id = :companyId')
            ->andWhere('ft.source = :source')
            ->andWhere("(ft.external_id LIKE :externalIdPrefix OR ft.source_data->>'_ingestion_resource' = :resourceType)")
            ->andWhere('ft.occurred_at >= :from')
            ->andWhere('ft.occurred_at < :toExclusive')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', IngestSource::OZON->value)
            ->setParameter('externalIdPrefix', 'ozon:accrual-by-day:%')
            ->setParameter('resourceType', OzonResourceType::ACCRUAL_BY_DAY)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE)
            ->groupBy('ft.type')
            ->orderBy(
                sprintf(
                    "CASE ft.type WHEN '%s' THEN 0 WHEN '%s' THEN 1 ELSE 2 END",
                    TransactionType::SALE->value,
                    TransactionType::REFUND->value,
                ),
                'ASC',
            )
            ->addOrderBy('ft.type', 'ASC');

        if (null !== $shopRef && '' !== trim($shopRef)) {
            $queryBuilder
                ->andWhere('ft.shop_ref = :shopRef')
                ->setParameter('shopRef', trim($shopRef));
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(
            static function (array $row): ReconciliationByTypeView {
                $type = (string) $row['type'];
                $transactionType = TransactionType::tryFrom($type);

                return new ReconciliationByTypeView(
                    type: $type,
                    typeLabel: $transactionType?->label() ?? $type,
                    canonAmountMinor: (int) $row['canon_amount_minor'],
                    txCount: (int) $row['tx_count'],
                );
            },
            $rows,
        );
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function monthBounds(int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));

        return [$from, $from->modify('first day of next month')];
    }

    /**
     * @param array<string, mixed>|null $totals
     */
    private function extractOzonTotalMinor(?array $totals): ?int
    {
        if (null === $totals || !array_key_exists('total_minor', $totals)) {
            return null;
        }

        $value = $totals['total_minor'];

        if (!is_int($value) && !(is_string($value) && 1 === preg_match('/^-?\d+$/', $value))) {
            return null;
        }

        return (int) $value;
    }

    private function latestDateTime(mixed $canonValue, ?\DateTimeImmutable $controlValue): string
    {
        $canonDate = null === $canonValue || '' === $canonValue
            ? null
            : new \DateTimeImmutable((string) $canonValue);

        $date = match (true) {
            null === $canonDate && null === $controlValue => new \DateTimeImmutable(),
            null === $canonDate => $controlValue,
            null === $controlValue => $canonDate,
            default => $canonDate > $controlValue ? $canonDate : $controlValue,
        };

        return $date
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
