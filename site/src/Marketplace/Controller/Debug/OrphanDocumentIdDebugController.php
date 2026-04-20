<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Debug;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pre-flight проверка для data-migration очистки `marketplace_costs.document_id`,
 * ссылающихся на уже удалённые `documents`. Возвращает totals + breakdown'ы,
 * чтобы до применения миграции Version20260420160500 оценить blast radius и
 * исключить посторонние сценарии появления orphan-строк.
 *
 * Только SELECT, никаких мутаций.
 *
 * Intentionally global scope — orphan detection requires visibility across all
 * companies. Read-only, temporary (@deprecated).
 *
 * @deprecated debug endpoint, remove after 2026-05-04 (2 недели от 2026-04-20).
 *             Follow-up: удалить файл OrphanDocumentIdDebugController.php
 *             и связанный route.
 */
#[Route('/_debug/marketplace')]
#[IsGranted('ROLE_USER')]
final class OrphanDocumentIdDebugController extends AbstractController
{
    private const SAMPLE_LIMIT = 50;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[Route('/orphan-document-ids', name: 'marketplace_debug_orphan_document_ids', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json([
            'meta' => [
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'scope'        => 'all_companies',
                'hint'         => 'Ожидание: десятки–сотни строк в январе–апреле 2026. Иное — стоп-сигнал, не применять миграцию.',
                'note'         => 'Endpoint scoped to marketplace_costs rows where document_id references a documents.id that no longer exists.',
            ],
            'totals'         => $this->totals(),
            'by_company'     => $this->byCompany(),
            'by_period_month'=> $this->byPeriodMonth(),
            'sample_rows'    => $this->sampleRows(),
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*)                                            AS orphan_rows,
                COUNT(DISTINCT c.company_id)                        AS affected_companies,
                COUNT(DISTINCT c.marketplace)                       AS affected_marketplaces,
                MIN(c.cost_date)::text                              AS earliest_cost_date,
                MAX(c.cost_date)::text                              AS latest_cost_date,
                MIN(c.updated_at)::text                             AS earliest_updated_at,
                MAX(c.updated_at)::text                             AS latest_updated_at
            FROM marketplace_costs c
            LEFT JOIN documents d ON d.id = c.document_id
            WHERE c.document_id IS NOT NULL
              AND d.id IS NULL
        SQL);

        $row = $row ?: [];

        return [
            'orphan_rows'           => (int) ($row['orphan_rows'] ?? 0),
            'affected_companies'    => (int) ($row['affected_companies'] ?? 0),
            'affected_marketplaces' => (int) ($row['affected_marketplaces'] ?? 0),
            'earliest_cost_date'    => $row['earliest_cost_date'] ?? null,
            'latest_cost_date'      => $row['latest_cost_date'] ?? null,
            'earliest_updated_at'   => $row['earliest_updated_at'] ?? null,
            'latest_updated_at'     => $row['latest_updated_at'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byCompany(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                c.company_id                 AS company_id,
                COUNT(*)                     AS orphan_rows,
                MIN(c.cost_date)::text       AS min_date,
                MAX(c.cost_date)::text       AS max_date
            FROM marketplace_costs c
            LEFT JOIN documents d ON d.id = c.document_id
            WHERE c.document_id IS NOT NULL
              AND d.id IS NULL
            GROUP BY c.company_id
            ORDER BY COUNT(*) DESC
        SQL);

        return array_map(static fn (array $row): array => [
            'company_id'  => $row['company_id'],
            'orphan_rows' => (int) $row['orphan_rows'],
            'min_date'    => $row['min_date'],
            'max_date'    => $row['max_date'],
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byPeriodMonth(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                to_char(c.cost_date, 'YYYY-MM') AS month,
                COUNT(*)                        AS rows
            FROM marketplace_costs c
            LEFT JOIN documents d ON d.id = c.document_id
            WHERE c.document_id IS NOT NULL
              AND d.id IS NULL
            GROUP BY to_char(c.cost_date, 'YYYY-MM')
            ORDER BY month ASC
        SQL);

        return array_map(static fn (array $row): array => [
            'month' => $row['month'],
            'rows'  => (int) $row['rows'],
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleRows(): array
    {
        $limit = self::SAMPLE_LIMIT;
        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                c.id                     AS id,
                c.company_id             AS company_id,
                c.marketplace            AS marketplace,
                c.cost_date::text        AS cost_date,
                c.document_id            AS document_id,
                c.updated_at::text       AS updated_at
            FROM marketplace_costs c
            LEFT JOIN documents d ON d.id = c.document_id
            WHERE c.document_id IS NOT NULL
              AND d.id IS NULL
            ORDER BY c.updated_at DESC, c.id ASC
            LIMIT {$limit}
        SQL);

        return array_map(static fn (array $row): array => [
            'id'          => $row['id'],
            'company_id'  => $row['company_id'],
            'marketplace' => $row['marketplace'],
            'cost_date'   => $row['cost_date'],
            'document_id' => $row['document_id'],
            'updated_at'  => $row['updated_at'],
        ], $rows);
    }
}
