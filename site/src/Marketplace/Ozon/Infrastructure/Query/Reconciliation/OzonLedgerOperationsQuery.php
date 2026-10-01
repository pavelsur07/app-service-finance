<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Query\Reconciliation;

use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Ozon\Domain\OzonCostCategory;
use App\Marketplace\Ozon\Domain\Reconciliation\OzonReconciliationBlockMap;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Записи учёта, из которых сложилась строка сверки (drill-down). Те же отборы, что у `OzonLedgerTotalsQuery`:
 * только записи, порождённые документами by-day, поэтому сумма записей совпадает с суммой строки сверки.
 * Возвращает `QueryBuilder` для Pagerfanta.
 */
final readonly class OzonLedgerOperationsQuery
{
    public const KIND_SALES = 'sales';
    public const KIND_RETURNS = 'returns';
    public const KIND_COSTS = 'costs';

    public function __construct(private Connection $connection)
    {
    }

    public function createSalesQueryBuilder(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->base($companyId, $from, $to)
            ->select('s.id', 's.sale_date AS operation_date', 's.external_order_id AS reference', 's.total_revenue AS amount')
            ->from('marketplace_sales', 's')
            ->innerJoin('s', 'marketplace_raw_documents', 'd', 'd.id = s.raw_document_id')
            ->where('s.company_id = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.sale_date >= :from')
            ->andWhere('s.sale_date <= :to')
            ->andWhere('d.api_endpoint = :endpoint')
            ->andWhere(self::dayProcessed('s', 'sale_date'))
            ->orderBy('s.sale_date', 'DESC')
            ->addOrderBy('s.id', 'ASC');
    }

    public function createReturnsQueryBuilder(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->base($companyId, $from, $to)
            ->select('r.id', 'r.return_date AS operation_date', 'r.external_return_id AS reference', 'r.refund_amount AS amount')
            ->from('marketplace_returns', 'r')
            ->innerJoin('r', 'marketplace_raw_documents', 'd', 'd.id = r.raw_document_id')
            ->where('r.company_id = :companyId')
            ->andWhere('r.marketplace = :marketplace')
            ->andWhere('r.return_date >= :from')
            ->andWhere('r.return_date <= :to')
            ->andWhere('d.api_endpoint = :endpoint')
            ->andWhere(self::dayProcessed('r', 'return_date'))
            ->orderBy('r.return_date', 'DESC')
            ->addOrderBy('r.id', 'ASC');
    }

    /**
     * Затраты блока или одной категории. Категория важнее блока; без обоих — все затраты периода.
     */
    public function createCostsQueryBuilder(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?OzonReconciliationBlock $block = null,
        ?string $categoryCode = null,
    ): QueryBuilder {
        $qb = $this->base($companyId, $from, $to)
            ->select(
                'c.id',
                'c.cost_date AS operation_date',
                'c.external_id AS reference',
                'c.amount AS amount',
                'c.operation_type AS operation_type',
                'cc.code AS category_code',
                'cc.name AS category_name',
            )
            ->from('marketplace_costs', 'c')
            ->innerJoin('c', 'marketplace_raw_documents', 'd', 'd.id = c.raw_document_id')
            ->leftJoin('c', 'marketplace_cost_categories', 'cc', 'cc.id = c.category_id')
            ->where('c.company_id = :companyId')
            ->andWhere('c.marketplace = :marketplace')
            ->andWhere('c.cost_date >= :from')
            ->andWhere('c.cost_date <= :to')
            ->andWhere('d.api_endpoint = :endpoint')
            ->andWhere(self::dayProcessed('c', 'cost_date'))
            ->orderBy('c.cost_date', 'DESC')
            ->addOrderBy('c.id', 'ASC');

        if (null !== $categoryCode) {
            // Затраты без категории в итогах стоят под служебным кодом: у них нет cc.code, отбираем по отсутствию связи.
            return OzonLedgerTotalsQuery::NO_CATEGORY_CODE === $categoryCode
                ? $qb->andWhere('c.category_id IS NULL')
                : $qb->andWhere('cc.code = :categoryCode')->setParameter('categoryCode', $categoryCode);
        }

        if (null === $block || !$block->isCostBlock()) {
            return $qb;
        }

        if (OzonReconciliationBlock::UNRECOGNIZED === $block) {
            return $qb
                ->andWhere('(cc.code IS NULL OR cc.code NOT IN (:recognized))')
                ->setParameter('recognized', OzonCostCategory::recognizedCodes(), ArrayParameterType::STRING);
        }

        $codes = array_values(array_filter(
            OzonCostCategory::recognizedCodes(),
            static fn (string $code): bool => OzonReconciliationBlockMap::forCategoryCode($code) === $block,
        ));

        return $qb
            ->andWhere('cc.code IN (:blockCodes)')
            ->setParameter('blockCodes', [] === $codes ? [''] : $codes, ArrayParameterType::STRING);
    }

    /**
     * День записи обработан завершённо: статус берётся у дня, а не у документа записи (см. `OzonLedgerTotalsQuery`).
     */
    private static function dayProcessed(string $alias, string $dateColumn): string
    {
        return sprintf(
            'EXISTS (SELECT 1 FROM marketplace_raw_documents dc WHERE dc.company_id = %1$s.company_id AND dc.marketplace = :marketplace AND dc.api_endpoint = :endpoint AND dc.processing_status = :completed AND dc.period_from = %1$s.%2$s)',
            $alias,
            $dateColumn,
        );
    }

    private function base(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', MarketplaceType::OZON->value)
            ->setParameter('endpoint', MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value)
            ->setParameter('completed', PipelineStatus::COMPLETED->value)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'));
    }
}
