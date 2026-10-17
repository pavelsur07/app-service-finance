<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Application\DTO\PlPreviewFactDTO;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Суммы по статьям ОПиУ, которые закрытие месяца провело бы по данным периода, — для
 * предпросмотра ОПиУ. Источники, правила и знаки — как у CloseMonthStageAction (продажи и
 * возвраты, затраты, «Реализация» Ozon), но берутся все строки периода, проведённые или нет.
 *
 * Затраты, как в UnprocessedCostsQuery, дают модуль суммы группы «категория затрат × сторно»;
 * модуль нелинеен, поэтому итог по статье надо брать вызовом без разреза по листингам
 * ($byListing = false), а не суммой листингов.
 */
final class PlPreviewFactsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<string> $excludedCostCategoryIds категории затрат, которые не брать из учёта
     *                                              (реклама, которую предпросмотр берёт из статистики)
     *
     * @return list<PlPreviewFactDTO>
     */
    public function fetch(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?MarketplaceType $marketplace,
        bool $byListing,
        array $excludedCostCategoryIds = [],
    ): array {
        $listing = static fn (string $alias): string => $byListing ? $alias.'.listing_id' : 'CAST(NULL AS UUID)';
        $marketplaceFilter = static fn (string $alias): string => null === $marketplace ? '' : "AND $alias.marketplace = :marketplace";
        $sign = 'CASE WHEN m.is_negative THEN -1 ELSE 1 END';

        $branches = [
            sprintf(
                "SELECT m.pl_category_id, %s AS listing_id, %s * (%s) AS amount
                 FROM marketplace_sales s
                 INNER JOIN marketplace_sale_mappings m
                     ON m.company_id = s.company_id
                     AND m.marketplace = s.marketplace
                     AND m.operation_type = 'sale'
                     AND m.is_active = true
                 WHERE s.company_id = :companyId
                     AND s.sale_date >= :periodFrom
                     AND s.sale_date <= :periodTo
                     %s",
                $listing('s'),
                $sign,
                MarketplacePlAmountSql::saleAmount(null),
                $marketplaceFilter('s'),
            ),
            sprintf(
                "SELECT m.pl_category_id, %s AS listing_id, %s * (%s) AS amount
                 FROM marketplace_returns r
                 LEFT JOIN marketplace_sales ms ON ms.id = r.sale_id
                 INNER JOIN marketplace_sale_mappings m
                     ON m.company_id = r.company_id
                     AND m.marketplace = r.marketplace
                     AND m.operation_type = 'return'
                     AND m.amount_source != 'return_realization'
                     AND m.is_active = true
                 WHERE r.company_id = :companyId
                     AND r.return_date >= :periodFrom
                     AND r.return_date <= :periodTo
                     %s",
                $listing('r'),
                $sign,
                MarketplacePlAmountSql::returnAmount(),
                $marketplaceFilter('r'),
            ),
            // Затраты: проведение берёт модуль суммы группы «категория затрат × сторно» (NULL —
            // своя группа, знак как у начисления). Доля листинга — его сумма со знаком итога
            // группы: доли складываются ровно в модуль, разрез по листингам остаётся линейным.
            sprintf(
                "SELECT g.pl_category_id, g.listing_id,
                     g.sign * g.listing_sum
                         * SIGN(SUM(g.listing_sum) OVER (PARTITION BY g.pl_category_id, g.category_id, g.storno_key, g.sign)) AS amount
                 FROM (
                     SELECT m.pl_category_id, %s AS listing_id, c.category_id,
                            (c.operation_type = 'storno') AS storno_key,
                            CASE WHEN m.is_negative <> COALESCE(c.operation_type = 'storno', false) THEN -1 ELSE 1 END AS sign,
                            SUM(c.amount) AS listing_sum
                     FROM marketplace_costs c
                     INNER JOIN marketplace_cost_pl_mappings m
                         ON m.cost_category_id = c.category_id
                         AND m.company_id = c.company_id
                     WHERE c.company_id = :companyId
                         AND c.cost_date >= :periodFrom
                         AND c.cost_date <= :periodTo
                         AND m.include_in_pl = true
                         AND m.pl_category_id IS NOT NULL
                         %s
                         %s
                     GROUP BY m.pl_category_id, %s c.category_id, (c.operation_type = 'storno'), m.is_negative
                 ) g",
                $listing('c'),
                $marketplaceFilter('c'),
                [] === $excludedCostCategoryIds ? '' : 'AND c.category_id NOT IN (:excludedCostCategoryIds)',
                $byListing ? 'c.listing_id,' : '',
            ),
        ];

        if (null === $marketplace || MarketplaceType::OZON === $marketplace) {
            foreach (['sale_realization' => 'r.total_amount', 'return_realization' => 'r.return_amount'] as $amountSource => $amount) {
                $branches[] = sprintf(
                    "SELECT m.pl_category_id, %s AS listing_id, %s * %s AS amount
                     FROM marketplace_ozon_realizations r
                     INNER JOIN marketplace_sale_mappings m
                         ON m.company_id = r.company_id
                         AND m.marketplace = '%s'
                         AND m.amount_source = '%s'
                         AND m.is_active = true
                     WHERE r.company_id = :companyId
                         AND r.period_from >= :periodFrom
                         AND r.period_to <= :periodTo
                         AND %s IS NOT NULL",
                    $listing('r'),
                    $sign,
                    $amount,
                    MarketplaceType::OZON->value,
                    $amountSource,
                    $amount,
                );
            }
        }

        $sql = sprintf(
            'SELECT facts.pl_category_id, facts.listing_id, SUM(facts.amount) AS amount
             FROM (%s) facts
             GROUP BY facts.pl_category_id, facts.listing_id
             HAVING SUM(facts.amount) <> 0
             ORDER BY facts.pl_category_id, facts.listing_id',
            implode("\nUNION ALL\n", $branches),
        );

        $params = [
            'companyId' => $companyId,
            'periodFrom' => $from->format('Y-m-d'),
            'periodTo' => $to->format('Y-m-d'),
        ];
        if (null !== $marketplace) {
            $params['marketplace'] = $marketplace->value;
        }
        $types = [];
        if ([] !== $excludedCostCategoryIds) {
            $params['excludedCostCategoryIds'] = $excludedCostCategoryIds;
            $types['excludedCostCategoryIds'] = ArrayParameterType::STRING;
        }

        $facts = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $facts[] = new PlPreviewFactDTO(
                (string) $row['pl_category_id'],
                null === $row['listing_id'] ? null : (string) $row['listing_id'],
                (string) $row['amount'],
            );
        }

        return $facts;
    }
}
