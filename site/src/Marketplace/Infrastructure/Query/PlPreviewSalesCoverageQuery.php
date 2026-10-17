<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Application\DTO\PlPreviewSalesCoverageDTO;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewSalesKind;
use Doctrine\DBAL\Connection;

/**
 * Покрытие продаж, возвратов и «Реализации» Ozon правилами ОПиУ за период: сколько строк и на
 * какую сумму, есть ли у вида хотя бы одно активное правило. Вид без правила при проведении в ОПиУ
 * не попадает. Правило считается для вида, только если закрытие возьмёт по нему сумму: для продаж
 * и возвратов — источники кроме «Реализации», для реализации — только её источники.
 */
final class PlPreviewSalesCoverageQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<PlPreviewSalesCoverageDTO>
     */
    public function fetch(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?MarketplaceType $marketplace): array
    {
        $branches = [
            $this->branch(
                PlPreviewSalesKind::SALE,
                'marketplace_sales s',
                's.marketplace',
                's.total_revenue',
                's.sale_date >= :periodFrom AND s.sale_date <= :periodTo'.(null === $marketplace ? '' : ' AND s.marketplace = :marketplace'),
                [AmountSource::SALE_GROSS, AmountSource::SALE_REVENUE, AmountSource::SALE_COST_PRICE],
                's',
            ),
            $this->branch(
                PlPreviewSalesKind::RETURN,
                'marketplace_returns s',
                's.marketplace',
                's.refund_amount',
                's.return_date >= :periodFrom AND s.return_date <= :periodTo'.(null === $marketplace ? '' : ' AND s.marketplace = :marketplace'),
                [AmountSource::RETURN_REFUND, AmountSource::RETURN_GROSS, AmountSource::RETURN_COST_PRICE],
                's',
            ),
        ];

        if (null === $marketplace || MarketplaceType::OZON === $marketplace) {
            $ozon = sprintf("'%s'", MarketplaceType::OZON->value);
            $realizationPeriod = 's.period_from >= :periodFrom AND s.period_to <= :periodTo';
            $branches[] = $this->branch(PlPreviewSalesKind::REALIZATION, 'marketplace_ozon_realizations s', $ozon, 's.total_amount', $realizationPeriod, [AmountSource::SALE_REALIZATION], 's');
            $branches[] = $this->branch(PlPreviewSalesKind::REALIZATION_RETURN, 'marketplace_ozon_realizations s', $ozon, 's.return_amount', $realizationPeriod.' AND s.return_amount IS NOT NULL', [AmountSource::RETURN_REALIZATION], 's');
        }

        $params = [
            'companyId' => $companyId,
            'periodFrom' => $from->format('Y-m-d'),
            'periodTo' => $to->format('Y-m-d'),
        ];
        if (null !== $marketplace) {
            $params['marketplace'] = $marketplace->value;
        }

        $result = [];
        foreach ($this->connection->fetchAllAssociative(implode("\nUNION ALL\n", $branches).' ORDER BY 1, 2', $params) as $row) {
            $result[] = new PlPreviewSalesCoverageDTO(
                MarketplaceType::from((string) $row['marketplace']),
                PlPreviewSalesKind::from((string) $row['kind']),
                (int) $row['rows_count'],
                (string) $row['amount'],
                (bool) $row['has_mapping'],
            );
        }

        return $result;
    }

    /**
     * @param list<AmountSource> $sources
     */
    private function branch(
        PlPreviewSalesKind $kind,
        string $table,
        string $marketplaceExpr,
        string $amountExpr,
        string $periodCondition,
        array $sources,
        string $alias,
    ): string {
        return sprintf(
            "(SELECT %1\$s AS marketplace, '%2\$s' AS kind, COUNT(*) AS rows_count,
                     COALESCE(SUM(%3\$s), 0) AS amount,
                     EXISTS (
                         SELECT 1 FROM marketplace_sale_mappings m
                         WHERE m.company_id = :companyId
                             AND m.marketplace = %1\$s
                             AND m.is_active = true
                             AND m.amount_source IN (%4\$s)
                     ) AS has_mapping
              FROM %5\$s
              WHERE %6\$s.company_id = :companyId AND %7\$s
              %8\$s)",
            $marketplaceExpr,
            $kind->value,
            $amountExpr,
            implode(', ', array_map(static fn (AmountSource $s): string => "'".$s->value."'", $sources)),
            $table,
            $alias,
            $periodCondition,
            // Реализация — только Ozon, маркетплейс — константа: группировать нечего, пустой вид не выводится.
            str_starts_with($marketplaceExpr, "'") ? 'HAVING COUNT(*) > 0' : 'GROUP BY '.$marketplaceExpr,
        );
    }
}
