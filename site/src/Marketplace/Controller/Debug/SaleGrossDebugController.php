<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Debug;

use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Временный debug-эндпоинт для подтверждения гипотезы о завышении SALE_GROSS
 * на multi-item posting'ах Ozon. Сравнивает формулу ОПиУ
 * (price_per_unit × quantity) с формулой Unit-экономики (total_revenue)
 * на реальных прод-данных.
 *
 * @deprecated debug endpoint, remove after 2026-05-04 (2 недели от 2026-04-20).
 *             Follow-up: удалить файл SaleGrossDebugController.php и его тесты.
 */
#[Route('/_debug/marketplace')]
#[IsGranted('ROLE_USER')]
final class SaleGrossDebugController extends AbstractController
{
    private const DATE_REGEX = '/^\d{4}-\d{2}-\d{2}$/';
    private const TOP_DELTA_LIMIT = 50;

    public function __construct(
        private readonly Connection $connection,
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    #[Route('/sale-gross', name: 'marketplace_debug_sale_gross', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        // IDOR-protection: company всегда берётся из сессии активной компании
        // пользователя; query-параметр company_id оставлен для читаемости URL
        // в спецификации, но игнорируется / валидируется на совпадение.
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        $requestedCompanyId = (string) $request->query->get('company_id', '');
        if ($requestedCompanyId !== '' && $requestedCompanyId !== $companyId) {
            return $this->json([
                'error' => 'company_id в query не совпадает с активной компанией пользователя',
            ], 403);
        }

        $marketplace = (string) $request->query->get('marketplace', '');
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');

        if (MarketplaceType::tryFrom($marketplace) === null) {
            return $this->json([
                'error' => 'marketplace must be one of: '
                    . implode(', ', array_map(static fn (MarketplaceType $m): string => $m->value, MarketplaceType::cases())),
            ], 400);
        }
        if (!preg_match(self::DATE_REGEX, $from) || !$this->isValidDate($from)) {
            return $this->json(['error' => 'from must be in Y-m-d format'], 400);
        }
        if (!preg_match(self::DATE_REGEX, $to) || !$this->isValidDate($to)) {
            return $this->json(['error' => 'to must be in Y-m-d format'], 400);
        }

        $params = [
            'companyId'  => $companyId,
            'marketplace' => $marketplace,
            'periodFrom' => $from,
            'periodTo'   => $to,
        ];

        return $this->json([
            'meta' => [
                'company_id'   => $companyId,
                'marketplace'  => $marketplace,
                'period'       => "{$from} – {$to}",
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'hint'         => 'by_quantity: если delta=0 на quantity=1 и delta>0 на quantity≥2 — гипотеза SALE_GROSS inflation подтверждена.',
            ],
            'totals'         => $this->totals($params),
            'by_quantity'    => $this->byQuantity($params),
            'top_delta_rows' => $this->topDeltaRows($params),
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function totals(array $params): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COALESCE(SUM(s.total_revenue), 0)                AS unit_revenue,
                COALESCE(SUM(s.price_per_unit * s.quantity), 0)  AS pnl_gross_revenue,
                COUNT(*)                                         AS rows_total,
                COUNT(*) FILTER (WHERE s.document_id IS NULL)    AS rows_open,
                COUNT(*) FILTER (WHERE s.document_id IS NOT NULL) AS rows_closed
            FROM marketplace_sales s
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date BETWEEN :periodFrom AND :periodTo
            SQL,
            $params,
        );

        $row = $row ?: [];
        $unit = (float) ($row['unit_revenue'] ?? 0);
        $pnl  = (float) ($row['pnl_gross_revenue'] ?? 0);

        return [
            'unit_revenue'      => round($unit, 2),
            'pnl_gross_revenue' => round($pnl, 2),
            'delta'             => round($pnl - $unit, 2),
            'rows_total'        => (int) ($row['rows_total'] ?? 0),
            'rows_open'         => (int) ($row['rows_open'] ?? 0),
            'rows_closed'       => (int) ($row['rows_closed'] ?? 0),
        ];
    }

    /**
     * @param array<string, string> $params
     * @return list<array<string, mixed>>
     */
    private function byQuantity(array $params): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                s.quantity                                      AS quantity,
                COUNT(*)                                        AS rows,
                COALESCE(SUM(s.total_revenue), 0)               AS unit_revenue,
                COALESCE(SUM(s.price_per_unit * s.quantity), 0) AS pnl_revenue
            FROM marketplace_sales s
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date BETWEEN :periodFrom AND :periodTo
            GROUP BY s.quantity
            ORDER BY s.quantity
            SQL,
            $params,
        );

        return array_map(static function (array $row): array {
            $unit = (float) $row['unit_revenue'];
            $pnl  = (float) $row['pnl_revenue'];

            return [
                'quantity'     => (int) $row['quantity'],
                'rows'         => (int) $row['rows'],
                'unit_revenue' => round($unit, 2),
                'pnl_revenue'  => round($pnl, 2),
                'delta'        => round($pnl - $unit, 2),
            ];
        }, $rows);
    }

    /**
     * @param array<string, string> $params
     * @return list<array<string, mixed>>
     */
    private function topDeltaRows(array $params): array
    {
        $limit = self::TOP_DELTA_LIMIT;
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                s.id                                    AS id,
                s.external_order_id                     AS external_order_id,
                s.sale_date::text                       AS sale_date,
                s.quantity                              AS quantity,
                s.price_per_unit                        AS price_per_unit,
                s.total_revenue                         AS total_revenue,
                (s.price_per_unit * s.quantity)         AS pnl_value,
                (s.price_per_unit * s.quantity - s.total_revenue) AS delta,
                s.document_id                           AS document_id
            FROM marketplace_sales s
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date BETWEEN :periodFrom AND :periodTo
              AND (s.price_per_unit * s.quantity - s.total_revenue) <> 0
            ORDER BY ABS(s.price_per_unit * s.quantity - s.total_revenue) DESC
            LIMIT {$limit}
            SQL,
            $params,
        );

        return array_map(static fn (array $row): array => [
            'id'                => $row['id'],
            'external_order_id' => $row['external_order_id'],
            'sale_date'         => $row['sale_date'],
            'quantity'          => (int) $row['quantity'],
            'price_per_unit'    => (float) $row['price_per_unit'],
            'total_revenue'     => (float) $row['total_revenue'],
            'pnl_value'         => round((float) $row['pnl_value'], 2),
            'delta'             => round((float) $row['delta'], 2),
            'document_id'       => $row['document_id'],
        ], $rows);
    }

    private function isValidDate(string $date): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        return $dt !== null && $dt->format('Y-m-d') === $date;
    }
}
