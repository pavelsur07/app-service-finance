<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Application\DTO\PlPreviewAdLedgerDTO;
use App\Marketplace\Domain\PlPreview\AdStatisticsCostCategories;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

/**
 * Рекламные категории под статистикой (AdStatisticsCostCategories), у которых есть правило со
 * статьёй ОПиУ и include_in_pl, — с учётной суммой за период. Сумма считается как у проведения
 * (UnprocessedCostsQuery): модуль суммы группы «сторно», знак правила, у сторно — обратный.
 * Категория без такого правила не возвращается: она и так в блоках «не размечено» / «исключено».
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
        foreach (MarketplaceType::cases() as $i => $type) {
            $code = AdStatisticsCostCategories::codeFor($type);
            if (null === $code || (null !== $marketplace && $marketplace !== $type)) {
                continue;
            }
            $pairs[] = sprintf('(mcc.marketplace = :mp%1$d AND mcc.code = :code%1$d)', $i);
            $params['mp'.$i] = $type->value;
            $params['code'.$i] = $code;
        }
        if ([] === $pairs) {
            return [];
        }

        $sql = sprintf(
            "SELECT mcc.marketplace, mcc.id AS cost_category_id, m.pl_category_id, m.is_negative,
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
                    ), 0) AS ledger_amount
             FROM marketplace_cost_categories mcc
             INNER JOIN marketplace_cost_pl_mappings m
                 ON m.cost_category_id = mcc.id
                 AND m.company_id = mcc.company_id
             WHERE mcc.company_id = :companyId
                 AND m.include_in_pl = true
                 AND m.pl_category_id IS NOT NULL
                 AND (%s)
             ORDER BY mcc.marketplace",
            implode(' OR ', $pairs),
        );

        $result = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            $result[] = new PlPreviewAdLedgerDTO(
                MarketplaceType::from((string) $row['marketplace']),
                (string) $row['cost_category_id'],
                (string) $row['pl_category_id'],
                (bool) $row['is_negative'],
                (string) $row['ledger_amount'],
            );
        }

        return $result;
    }
}
