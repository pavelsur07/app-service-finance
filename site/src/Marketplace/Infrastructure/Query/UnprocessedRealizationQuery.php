<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Агрегирует необработанные строки реализации Ozon для генерации ОПиУ.
 *
 * JOIN с marketplace_sale_mappings где amount_source = 'sale_realization'
 * даёт pl_category_id для строки "Выручка реализации" в ОПиУ.
 *
 * WHERE pl_document_id IS NULL — только необработанные строки.
 */
final class UnprocessedRealizationQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{
     *     pl_category_id: string,
     *     project_direction_id: ?string,
     *     is_negative: bool,
     *     description_template: ?string,
     *     sort_order: int,
     *     total_amount: string
     * }>
     */
    public function execute(
        string $companyId,
        string $periodFrom,
        string $periodTo,
    ): array {
        $sql = <<<'SQL'
            SELECT
                m.pl_category_id,
                m.project_direction_id,
                m.is_negative,
                m.description_template,
                m.sort_order,
                SUM(r.total_amount) AS total_amount
            FROM marketplace_ozon_realizations r
            INNER JOIN marketplace_sale_mappings m
                ON m.company_id = r.company_id
                AND m.marketplace = 'ozon'
                AND m.amount_source = 'sale_realization'
                AND m.is_active = true
            WHERE r.company_id = :companyId
                AND r.period_from >= :periodFrom
                AND r.period_to <= :periodTo
                AND r.pl_document_id IS NULL
            GROUP BY
                m.pl_category_id,
                m.project_direction_id,
                m.is_negative,
                m.description_template,
                m.sort_order
            HAVING SUM(r.total_amount) != 0
            ORDER BY m.sort_order ASC
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'companyId'  => $companyId,
            'periodFrom' => $periodFrom,
            'periodTo'   => $periodTo,
        ]);
    }
}
