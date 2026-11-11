<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Application\DTO\PlPreviewAdLedgerDTO;
use App\Marketplace\Domain\PlPreview\AdStatisticsCostCategories;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Рекламные категории под статистикой (AdStatisticsCostCategories), у которых есть правило со
 * статьёй ОПиУ и include_in_pl, — с учётной суммой за период. Сумма считается как у проведения
 * (UnprocessedCostsQuery): модуль суммы группы «сторно», знак правила, у сторно — обратный.
 * Категория без такого правила не возвращается: она и так в блоках «не размечено» / «исключено».
 *
 * outside_amount — прочие категории того же маркетплейса в той же статье ОПиУ, которых нет в
 * рекламной статистике (оплата за заказ, рассылки, подписки): учёт + они = строка статьи.
 */
final class PlPreviewAdLedgerQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<PlPreviewAdLedgerDTO>
     */
    public function fetch(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?MarketplaceType $marketplace): array
    {
        $pairs = [];
        $params = [
            'companyId' => $companyId,
            'periodFrom' => $from->format('Y-m-d'),
            'periodTo' => $to->format('Y-m-d'),
        ];
        $kinds = [];
        foreach (MarketplaceType::cases() as $type) {
            if (null !== $marketplace && $marketplace !== $type) {
                continue;
            }
            foreach (AdStatisticsCostCategories::ledgerCategories($type) as $code => $kind) {
                $i = count($pairs);
                $pairs[] = sprintf('(mcc.marketplace = :mp%1$d AND mcc.code = :code%1$d)', $i);
                $params['mp'.$i] = $type->value;
                $params['code'.$i] = $code;
                $kinds[$type->value.'|'.$code] = $kind;
            }
        }
        if ([] === $pairs) {
            return [];
        }

        $sql = sprintf(
            "SELECT mcc.marketplace, mcc.code, mcc.id AS cost_category_id, m.pl_category_id, m.is_negative,
                    COALESCE((
                        SELECT SUM(g.amount)
                        FROM (
                            SELECT CASE WHEN m.is_negative <> COALESCE(c.operation_type = 'storno', false) THEN -1 ELSE 1 END
                                       * ABS(SUM(c.amount)) AS amount
                            FROM marketplace_costs c
                            WHERE c.company_id = :companyId
                                AND c.category_id = mcc.id
                                AND c.cost_date >= :periodFrom
                                AND c.cost_date <= :periodTo
                            GROUP BY (c.operation_type = 'storno')
                        ) g
                    ), 0) AS ledger_amount,
                    COALESCE((
                        SELECT SUM(o.amount)
                        FROM (
                            SELECT CASE WHEN om.is_negative <> COALESCE(c.operation_type = 'storno', false) THEN -1 ELSE 1 END
                                       * ABS(SUM(c.amount)) AS amount
                            FROM marketplace_costs c
                            INNER JOIN marketplace_cost_categories occ ON occ.id = c.category_id
                            INNER JOIN marketplace_cost_pl_mappings om
                                ON om.cost_category_id = c.category_id
                                AND om.company_id = c.company_id
                            WHERE c.company_id = :companyId
                                AND c.marketplace = mcc.marketplace
                                AND om.pl_category_id = m.pl_category_id
                                AND om.include_in_pl = true
                                AND occ.code NOT IN (:statisticsCodes)
                                AND c.cost_date >= :periodFrom
                                AND c.cost_date <= :periodTo
                            GROUP BY c.category_id, (c.operation_type = 'storno'), om.is_negative
                        ) o
                    ), 0) AS outside_amount
             FROM marketplace_cost_categories mcc
             INNER JOIN marketplace_cost_pl_mappings m
                 ON m.cost_category_id = mcc.id
                 AND m.company_id = mcc.company_id
             WHERE mcc.company_id = :companyId
                 AND m.include_in_pl = true
                 AND m.pl_category_id IS NOT NULL
                 AND (%s)
             ORDER BY mcc.marketplace, mcc.code",
            implode(' OR ', $pairs),
        );

        $params['statisticsCodes'] = AdStatisticsCostCategories::allCodes();

        $result = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params, ['statisticsCodes' => ArrayParameterType::STRING]) as $row) {
            $result[] = new PlPreviewAdLedgerDTO(
                MarketplaceType::from((string) $row['marketplace']),
                $kinds[$row['marketplace'].'|'.$row['code']] ?? null,
                (string) $row['cost_category_id'],
                (string) $row['pl_category_id'],
                (bool) $row['is_negative'],
                (string) $row['ledger_amount'],
                (string) $row['outside_amount'],
            );
        }

        return $result;
    }
}
