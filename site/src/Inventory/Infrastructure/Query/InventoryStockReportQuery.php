<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Query;

use App\Inventory\Enum\StockStatus;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Webmozart\Assert\Assert;

/**
 * @phpstan-type BreakdownColumn array{value: string, label: string}
 */
final class InventoryStockReportQuery
{
    public const PER_PAGE = 30;

    /** Тип склада, которым нормализатор помечает строку без fulfillment_type. */
    private const FULFILLMENT_UNKNOWN = 'unknown';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Остатки «на дату»: ближайший день со снимком не позже запрошенной даты.
     * null — снимков по источнику на эту дату и раньше нет.
     */
    public function findEffectiveSnapshotDate(string $companyId, MarketplaceType $source, \DateTimeImmutable $date): ?\DateTimeImmutable
    {
        Assert::uuid($companyId);

        // ORDER BY + LIMIT 1, а не MAX(): планировщик идёт обратным сканом по
        // idx_inventory_stock_company_source_date и останавливается на первой подходящей строке.
        $value = $this->connection->createQueryBuilder()
            ->select('s.snapshot_date')
            ->from('inventory_stock_snapshots', 's')
            ->where('s.company_id = :companyId')
            ->andWhere('s.source = :source')
            ->andWhere('s.snapshot_date <= :date')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->orderBy('s.snapshot_date', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if (false === $value || null === $value) {
            return null;
        }

        return new \DateTimeImmutable((string) $value);
    }

    /**
     * Колонки разбивки «Доступно расчётно» по типам склада: алиас колонки строки => значение и заголовок.
     *
     * Набор зависит от данных: показываются только типы, реально встретившиеся в снимке.
     *
     * @return array<string, BreakdownColumn>
     */
    public function getBreakdownColumns(string $companyId, MarketplaceType $source, \DateTimeImmutable $snapshotDate): array
    {
        $values = $this->baseQueryBuilder($companyId, $source, $snapshotDate)
            ->select(sprintf('DISTINCT %s AS breakdown_value', $this->breakdownExpression($source)))
            ->executeQuery()
            ->fetchFirstColumn();

        /** @var list<string> $values */
        $values = array_map(static fn (mixed $value): string => (string) $value, $values);
        usort($values, fn (string $left, string $right): int => $this->breakdownOrder($source, $left) <=> $this->breakdownOrder($source, $right)
            ?: strcmp($left, $right));

        $columns = [];
        foreach ($values as $value) {
            $columns['bd_'.$value] = ['value' => $value, 'label' => $this->breakdownLabel($source, $value)];
        }

        return $columns;
    }

    /**
     * Строка отчёта — один SKU за день: количества суммируются по всем складам, типам и статусам.
     *
     * @param array<string, BreakdownColumn> $breakdownColumns из getBreakdownColumns() с теми же фильтрами
     */
    public function getPage(
        string $companyId,
        int $page,
        int $perPage,
        MarketplaceType $source,
        \DateTimeImmutable $snapshotDate,
        array $breakdownColumns = [],
    ): Pagerfanta {
        $qb = $this->baseQueryBuilder($companyId, $source, $snapshotDate)
            ->select(
                's.source_sku',
                'MAX(s.snapshot_at) AS snapshot_at',
                'MAX(s.source_offer_id) AS source_offer_id',
                // mapping_status считается по source_sku, поэтому внутри группы он один и тот же.
                'MAX(s.mapping_status) AS mapping_status',
                'SUM(s.quantity) AS quantity',
                'SUM(s.reserved_quantity) AS reserved_quantity',
                'SUM(s.quantity - s.reserved_quantity) AS available_for_sale',
            )
            ->groupBy('s.source_sku')
            ->orderBy('MAX(s.snapshot_at)', 'DESC')
            ->addOrderBy('s.source_sku', 'DESC');

        $breakdownExpression = $this->breakdownExpression($source);
        $index = 0;
        foreach ($breakdownColumns as $alias => $column) {
            $parameter = 'breakdown_'.$index++;
            $qb
                ->addSelect(sprintf(
                    'COALESCE(SUM(s.quantity - s.reserved_quantity) FILTER (WHERE %s = :%s), 0.000) AS %s',
                    $breakdownExpression,
                    $parameter,
                    $this->connection->quoteIdentifier($alias),
                ))
                ->setParameter($parameter, $column['value']);
        }

        return Pagerfanta::createForCurrentPageWithMaxPerPage(
            new QueryAdapter($qb, static function (QueryBuilder $countQb): QueryBuilder {
                // Страница считается по SKU, а не по строкам снимка; COALESCE — чтобы
                // SKU без значения не выпал из COUNT(DISTINCT) и не сдвинул пагинацию.
                return $countQb
                    ->select("COUNT(DISTINCT COALESCE(s.source_sku, '')) AS total_results")
                    ->resetGroupBy()
                    ->resetOrderBy()
                    ->setMaxResults(1);
            }),
            max(1, $page),
            min(100, max(1, $perPage)),
        );
    }

    private function baseQueryBuilder(
        string $companyId,
        MarketplaceType $source,
        \DateTimeImmutable $snapshotDate,
    ): QueryBuilder {
        Assert::uuid($companyId);

        return $this->connection->createQueryBuilder()
            ->from('inventory_stock_snapshots', 's')
            ->where('s.company_id = :companyId')
            ->andWhere('s.source = :source')
            ->andWhere('s.snapshot_date = :snapshotDate')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('snapshotDate', $snapshotDate, Types::DATE_IMMUTABLE);
    }

    /**
     * У Ozon тип склада несёт fulfillment_type (fbo/fbs), у Wildberries он всегда один (fbw),
     * а остатки различаются статусом — на складе, в пути к клиенту, в пути от клиента.
     */
    private function breakdownExpression(MarketplaceType $source): string
    {
        return MarketplaceType::WILDBERRIES === $source
            ? 's.status'
            : sprintf("COALESCE(LOWER(NULLIF(TRIM(s.fulfillment_type), '')), '%s')", self::FULFILLMENT_UNKNOWN);
    }

    private function breakdownOrder(MarketplaceType $source, string $value): int
    {
        if (MarketplaceType::WILDBERRIES !== $source) {
            return 0;
        }

        $position = array_search($value, array_column(StockStatus::cases(), 'value'), true);

        return false === $position ? \PHP_INT_MAX : $position;
    }

    private function breakdownLabel(MarketplaceType $source, string $value): string
    {
        if (MarketplaceType::WILDBERRIES === $source) {
            return StockStatus::tryFrom($value)?->label() ?? $value;
        }

        return self::FULFILLMENT_UNKNOWN === $value ? 'Без типа' : mb_strtoupper($value);
    }
}
