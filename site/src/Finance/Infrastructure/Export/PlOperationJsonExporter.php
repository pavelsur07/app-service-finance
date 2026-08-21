<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Export;

use Doctrine\DBAL\Connection;

/**
 * Плоская выгрузка операций ОПиУ: строка = одна DocumentOperation
 * с продублированными полями своего документа.
 */
final readonly class PlOperationJsonExporter
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(string $companyId, string $companyName, \DateTimeImmutable $exportedAt): array
    {
        // ponytail: без лимита и стриминга — как остальные export.json в проекте.
        // Потолок — объём выгрузки в память; апгрейд — StreamedResponse + iterateAssociative().
        $rows = $this->connection->executeQuery(
            <<<'SQL'
                SELECT
                    o.id AS operation_id,
                    d.id AS document_id,
                    d.date,
                    d.number,
                    d.type AS doc_type,
                    d.status,
                    d.description,
                    d.source,
                    d.stream,
                    c.code AS category_code,
                    c.name AS category,
                    c.flow,
                    o.amount,
                    o.comment,
                    COALESCE(oc.name, dc.name) AS counterparty,
                    COALESCE(op.name, dp.name) AS project
                FROM document_operations o
                INNER JOIN documents d ON o.document_id = d.id
                LEFT JOIN pl_categories c ON o.category_id = c.id AND c.company_id = d.company_id
                LEFT JOIN "counterparty" oc ON o.counterparty_id = oc.id AND oc.company_id = d.company_id
                LEFT JOIN "counterparty" dc ON d.counterparty_id = dc.id AND dc.company_id = d.company_id
                LEFT JOIN project_directions op ON o.project_direction_id = op.id AND op.company_id = d.company_id
                LEFT JOIN project_directions dp ON d.project_direction_id = dp.id AND dp.company_id = d.company_id
                -- company_id продублирован в каждом JOIN справочника: FK на pl_categories,
                -- counterparty и project_directions не несут компанию, поэтому строка с чужой
                -- ссылкой отдаст NULL вместо чужого названия
                WHERE d.company_id = :companyId
                ORDER BY d.date DESC, d.number, o.id
                SQL,
            ['companyId' => $companyId],
        )->fetchAllAssociative();

        $operations = [];
        foreach ($rows as $row) {
            $operations[] = [
                'operation_id' => $row['operation_id'],
                'document_id' => $row['document_id'],
                'date' => substr((string) $row['date'], 0, 10),
                'number' => $row['number'],
                'doc_type' => $row['doc_type'],
                'status' => $row['status'],
                'description' => $row['description'],
                'source' => $row['source'],
                'stream' => $row['stream'],
                'category_code' => $row['category_code'],
                'category' => $row['category'],
                'flow' => $row['flow'],
                // amount остаётся строкой: NUMERIC(15,2), float потеряет копейки
                'amount' => $row['amount'],
                // ponytail: контрагент и проект операции с фоллбэком на документ — так же,
                // как их показывает экран «Операции ОПиУ»
                'counterparty' => $row['counterparty'],
                'project' => $row['project'],
                'comment' => $row['comment'],
            ];
        }

        return [
            'exported_at' => $exportedAt->format(\DateTimeInterface::ATOM),
            'company' => $companyName,
            'count' => \count($operations),
            'operations' => $operations,
        ];
    }
}
