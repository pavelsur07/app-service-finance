<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * DBAL-запросы для preflight проверок этапа COSTS.
 *
 * Проверяет:
 *   - количество затрат за период
 *   - количество затрат без решения по ОПиУ (блокирующее)
 *   - количество затрат с include_in_pl = false (информационно)
 *   - наличие уже обработанных затрат (аномалия — блокирующее)
 *   - затраты в категориях вне каталога маркетплейса (нераспознанные)
 *   - контрольная сумма — сколько возьмёт закрытие (информационно, в snapshot проверок)
 */
final class PreflightCostsQuery
{
    /**
     * Затрата без решения по ОПиУ: маппинга нет либо он включён в ОПиУ без
     * статьи — такая затрата молча не попадёт в ОПиУ. Осознанное исключение
     * (include_in_pl = false) — решение. Алиас `m` — marketplace_cost_pl_mappings.
     * Тем же условием гейт UnmappedCostsQuery ищет затраты вне ОПиУ.
     */
    public const string WITHOUT_PL_DECISION = '(m.id IS NULL OR (m.include_in_pl = true AND m.pl_category_id IS NULL))';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * net_amount_for_pl — сумма, которую возьмёт закрытие: только ещё не
     * обработанные строки (document_id IS NULL), как UnprocessedCostsQuery;
     * $preliminary — ещё и фильтр оперативного закрытия (PreliminaryCostFilter).
     * Остальные счётчики — по всем затратам периода.
     */
    public function getCostsStats(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        bool $preliminary = false,
    ): array {
        [$preliminaryFilter, $preliminaryParams, $preliminaryTypes] = PreliminaryCostFilter::build($marketplace, $preliminary);
        $withoutPlDecision = self::WITHOUT_PL_DECISION;

        return $this->connection->fetchAssociative(
            <<<SQL
            SELECT
                COUNT(*)                                                        AS total,
                COUNT(*) FILTER (WHERE c.document_id IS NOT NULL)              AS already_processed,
                COUNT(*) FILTER (
                    WHERE m.id IS NULL OR m.pl_category_id IS NULL
                )                                                               AS without_pl_mapping,
                COUNT(*) FILTER (WHERE $withoutPlDecision)                                                               AS without_pl_decision,
                COUNT(*) FILTER (
                    WHERE m.id IS NOT NULL AND m.include_in_pl = false
                )                                                               AS excluded_from_pl,
                COALESCE(
                    SUM(CASE WHEN c.operation_type = 'storno' THEN -ABS(c.amount) ELSE ABS(c.amount) END)
                        FILTER (WHERE c.document_id IS NULL AND m.include_in_pl = true AND m.pl_category_id IS NOT NULL $preliminaryFilter),
                    0
                )                                                               AS net_amount_for_pl
            FROM marketplace_costs c
            LEFT JOIN marketplace_cost_categories mcc ON mcc.id = c.category_id
            LEFT JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = c.category_id
                AND m.company_id = c.company_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
            SQL,
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
                ...$preliminaryParams,
            ],
            $preliminaryTypes,
        ) ?: [
            'total' => 0,
            'already_processed' => 0,
            'without_pl_mapping' => 0,
            'without_pl_decision' => 0,
            'excluded_from_pl' => 0,
            'net_amount_for_pl' => '0',
        ];
    }

    /**
     * Затраты в категориях, которых нет в каталоге маркетплейса, — строка на
     * категорию (название + код): неизвестная услуга Ozon (`ozon_unknown_<type_id>`),
     * удержание WB, не описанное в WbCostCategory, и легаси-корзина
     * `ozon_other_service`. Код нужен в ключе: у `ozon_unknown_<type_id>`, чьего
     * типа нет в справочнике Ozon, название одинаковое, и разные услуги слились бы.
     *
     * У легаси-корзины одна категория на все неизвестные услуги, поэтому
     * название берётся из description — там исходное имя услуги; без него —
     * название категории, а не пустая строка.
     *
     * decided — по категории у компании есть решение по ОПиУ (условие
     * without_pl_decision из getCostsStats); блокировать ли такие строки,
     * решает вызывающий.
     *
     * @param list<string> $knownCodes коды каталога; всё остальное — нераспознанное
     *
     * @return list<array{service_name: string, category_code: string, count: int, decided: bool}>
     */
    public function getUnrecognizedCosts(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        array $knownCodes,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                CASE WHEN cc.code = 'ozon_other_service' THEN COALESCE(NULLIF(BTRIM(c.description), ''), cc.name) ELSE cc.name END AS service_name,
                cc.code                                                                        AS category_code,
                COUNT(c.id)                                                                    AS count,
                NOT (m.id IS NULL OR (m.include_in_pl = true AND m.pl_category_id IS NULL))   AS decided
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            LEFT JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = cc.id
                AND m.company_id = c.company_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
              AND cc.code NOT IN (:knownCodes)
            GROUP BY 1, 2, 4
            ORDER BY COUNT(c.id) DESC, 1, 2
            SQL,
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
                'knownCodes' => $knownCodes,
            ],
            [
                'knownCodes' => ArrayParameterType::STRING,
            ],
        );

        return array_map(
            static fn (array $row): array => [
                'service_name' => (string) $row['service_name'],
                'category_code' => (string) $row['category_code'],
                'count' => (int) $row['count'],
                'decided' => (bool) $row['decided'],
            ],
            $rows,
        );
    }

    /**
     * Категории затрат без решения по ОПиУ за период (условие without_pl_decision
     * из getCostsStats).
     * Используется для отображения пользователю что именно нужно замапить.
     *
     * @return array<int, array{category_id: string, category_name: string, category_code: string, costs_count: int|string}>
     */
    public function getCategoriesWithoutMapping(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $withoutPlDecision = self::WITHOUT_PL_DECISION;

        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                cc.id   AS category_id,
                cc.name AS category_name,
                cc.code AS category_code,
                COUNT(mc.id) AS costs_count
            FROM marketplace_costs mc
            JOIN marketplace_cost_categories cc ON mc.category_id = cc.id
            LEFT JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = cc.id
                AND m.company_id = mc.company_id
            WHERE mc.company_id  = :companyId
              AND mc.marketplace = :marketplace
              AND mc.cost_date >= :periodFrom
              AND mc.cost_date <= :periodTo
              AND $withoutPlDecision
            GROUP BY cc.id, cc.name, cc.code
            ORDER BY COUNT(mc.id) DESC
            SQL,
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ],
        );
    }
}
