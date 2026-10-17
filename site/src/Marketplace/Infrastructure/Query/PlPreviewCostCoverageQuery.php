<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Application\DTO\PlPreviewCostCoverageDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewCostStatus;
use Doctrine\DBAL\Connection;

/**
 * Затраты периода по категориям и их судьба при проведении: размечены, исключены из ОПиУ или
 * без решения (условие гейта unmapped-check). Берутся все строки периода, проведённые или нет.
 *
 * Нетто-сумма — как у проведения (UnprocessedCostsQuery): модуль суммы группы «категория ×
 * сторно», нетто = начисления − сторно. fetchTotal() считает то же без джойна правил — для
 * сверки «итог = размечено + исключено + не размечено».
 */
final class PlPreviewCostCoverageQuery
{
    // NULL — своя группа, как в UnprocessedCostsQuery; знак у неё — как у начисления.
    private const string IS_STORNO = "(c.operation_type = 'storno')";

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<PlPreviewCostCoverageDTO>
     */
    public function fetch(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?MarketplaceType $marketplace): array
    {
        $sql = sprintf(
            "SELECT g.marketplace, g.category_id, mcc.code, mcc.name, g.status,
                    SUM(g.rows_count) AS rows_count, SUM(g.net_amount) AS net_amount
             FROM (
                 SELECT r.marketplace, r.category_id, r.status, COUNT(*) AS rows_count,
                        CASE WHEN COALESCE(r.storno_key, false) THEN -1 ELSE 1 END * ABS(SUM(r.amount)) AS net_amount
                 FROM (
                     SELECT c.marketplace, c.category_id, c.amount, %s AS storno_key,
                            CASE
                                WHEN c.category_id IS NULL OR %s THEN '%s'
                                WHEN m.include_in_pl = false THEN '%s'
                                ELSE '%s'
                            END AS status
                     FROM marketplace_costs c
                     LEFT JOIN marketplace_cost_pl_mappings m
                         ON m.cost_category_id = c.category_id
                         AND m.company_id = c.company_id
                     WHERE %s
                 ) r
                 GROUP BY r.marketplace, r.category_id, r.status, r.storno_key
             ) g
             LEFT JOIN marketplace_cost_categories mcc ON mcc.id = g.category_id
             GROUP BY g.marketplace, g.category_id, mcc.code, mcc.name, g.status
             ORDER BY g.marketplace, g.status, mcc.name",
            self::IS_STORNO,
            PreflightCostsQuery::WITHOUT_PL_DECISION,
            PlPreviewCostStatus::UNMAPPED->value,
            PlPreviewCostStatus::EXCLUDED->value,
            PlPreviewCostStatus::MAPPED->value,
            $this->where($marketplace),
        );

        $result = [];
        foreach ($this->connection->fetchAllAssociative($sql, $this->params($companyId, $from, $to, $marketplace)) as $row) {
            $result[] = new PlPreviewCostCoverageDTO(
                MarketplaceType::from((string) $row['marketplace']),
                null === $row['category_id'] ? null : (string) $row['category_id'],
                null === $row['code'] ? null : (string) $row['code'],
                null === $row['name'] ? null : (string) $row['name'],
                PlPreviewCostStatus::from((string) $row['status']),
                (int) $row['rows_count'],
                (string) $row['net_amount'],
            );
        }

        return $result;
    }

    /**
     * Нетто всех затрат периода без правил маппинга.
     */
    public function fetchTotal(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?MarketplaceType $marketplace): string
    {
        $sql = sprintf(
            'SELECT COALESCE(SUM(g.net_amount), 0)
             FROM (
                 SELECT CASE WHEN COALESCE(%1$s, false) THEN -1 ELSE 1 END * ABS(SUM(c.amount)) AS net_amount
                 FROM marketplace_costs c
                 WHERE %2$s
                 GROUP BY c.marketplace, c.category_id, %1$s
             ) g',
            self::IS_STORNO,
            $this->where($marketplace),
        );

        return (string) $this->connection->fetchOne($sql, $this->params($companyId, $from, $to, $marketplace));
    }

    private function where(?MarketplaceType $marketplace): string
    {
        return 'c.company_id = :companyId
                AND c.cost_date >= :periodFrom
                AND c.cost_date <= :periodTo'
            .(null === $marketplace ? '' : ' AND c.marketplace = :marketplace');
    }

    /**
     * @return array<string, string>
     */
    private function params(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?MarketplaceType $marketplace): array
    {
        $params = [
            'companyId' => $companyId,
            'periodFrom' => $from->format('Y-m-d'),
            'periodTo' => $to->format('Y-m-d'),
        ];
        if (null !== $marketplace) {
            $params['marketplace'] = $marketplace->value;
        }

        return $params;
    }
}
