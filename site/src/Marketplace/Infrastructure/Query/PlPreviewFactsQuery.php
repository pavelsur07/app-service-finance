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
     * @param bool $byProject разрез по маркетплейсу и проекту правила маппинга
     *                        (у затрат проекта правила нет — null); фолбэк на
     *                        проект подключения — у вызывающего, как при проведении
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
        bool $byProject = false,
    ): array {
        $listing = static fn (string $alias): string => $byListing ? $alias.'.listing_id' : 'CAST(NULL AS UUID)';
        $project = static fn (string $marketplaceSql, string $projectSql): string => $byProject
            ? sprintf('CAST(%s AS TEXT) AS marketplace, CAST(%s AS TEXT) AS project_id', $marketplaceSql, $projectSql)
            : 'CAST(NULL AS TEXT) AS marketplace, CAST(NULL AS TEXT) AS project_id';
        $marketplaceFilter = static fn (string $alias): string => null === $marketplace ? '' : "AND $alias.marketplace = :marketplace";
        $sign = 'CASE WHEN m.is_negative THEN -1 ELSE 1 END';

        $branches = [
            sprintf(
                "SELECT m.pl_category_id, %s AS listing_id, %s, %s * (%s) AS amount
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
                $project('s.marketplace', 'm.project_direction_id'),
                $sign,
                MarketplacePlAmountSql::saleAmount(null),
                $marketplaceFilter('s'),
            ),
            sprintf(
                "SELECT m.pl_category_id, %s AS listing_id, %s, %s * (%s) AS amount
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
                $project('r.marketplace', 'm.project_direction_id'),
                $sign,
                MarketplacePlAmountSql::returnAmount(),
                $marketplaceFilter('r'),
            ),
            // Затраты: проведение берёт модуль суммы группы «категория затрат × сторно» (NULL —
            // своя группа, знак как у начисления). Доля листинга — его сумма со знаком итога
            // группы: доли складываются ровно в модуль, разрез по листингам остаётся линейным.
            sprintf(
                "SELECT g.pl_category_id, g.listing_id, g.marketplace, g.project_id,
                     g.sign * g.listing_sum
                         * SIGN(SUM(g.listing_sum) OVER (PARTITION BY g.pl_category_id, g.marketplace, g.category_id, g.storno_key, g.sign)) AS amount
                 FROM (
                     SELECT m.pl_category_id, %s AS listing_id, %s, c.category_id,
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
                     GROUP BY m.pl_category_id, %s %s c.category_id, (c.operation_type = 'storno'), m.is_negative
                 ) g",
                $listing('c'),
                $project('c.marketplace', 'NULL'),
                $marketplaceFilter('c'),
                [] === $excludedCostCategoryIds ? '' : 'AND c.category_id NOT IN (:excludedCostCategoryIds)',
                $byListing ? 'c.listing_id,' : '',
                $byProject ? 'c.marketplace,' : '',
            ),
        ];

        if (null === $marketplace || MarketplaceType::OZON === $marketplace) {
            foreach (['sale_realization' => 'r.total_amount', 'return_realization' => 'r.return_amount'] as $amountSource => $amount) {
                $branches[] = sprintf(
                    "SELECT m.pl_category_id, %s AS listing_id, %s, %s * %s AS amount
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
                    $project("'".MarketplaceType::OZON->value."'", 'm.project_direction_id'),
                    $sign,
                    $amount,
                    MarketplaceType::OZON->value,
                    $amountSource,
                    $amount,
                );
            }
        }

        $sql = sprintf(
            'SELECT facts.pl_category_id, facts.listing_id, facts.marketplace, facts.project_id, SUM(facts.amount) AS amount
             FROM (%s) facts
             GROUP BY facts.pl_category_id, facts.listing_id, facts.marketplace, facts.project_id
             HAVING SUM(facts.amount) <> 0
             ORDER BY facts.pl_category_id, facts.listing_id, facts.marketplace, facts.project_id',
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
                null === $row['marketplace'] ? null : MarketplaceType::from((string) $row['marketplace']),
                null === $row['project_id'] ? null : (string) $row['project_id'],
            );
        }

        return $facts;
    }
}
