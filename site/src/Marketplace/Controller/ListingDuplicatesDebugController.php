<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-эндпоинт диагностики дублей marketplace_listings.
 *
 * Использование:
 *   GET /marketplace/listings/debug/duplicates
 *   GET /marketplace/listings/debug/duplicates?sku=2658745603
 *
 * Отвечает на вопросы:
 *   1. Применилась ли миграция Version20260331120000?
 *   2. Есть ли ещё дубли листингов?
 *   3. Для конкретного SKU — одна запись или две? Одинаковый ли listing_id у записей себестоимости?
 */
#[Route('/marketplace/listings/debug')]
#[IsGranted('ROLE_USER')]
final class ListingDuplicatesDebugController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly Connection $connection,
    ) {}

    /**
     * Полная диагностика дублей листингов для текущей компании.
     *
     * GET /marketplace/listings/debug/duplicates
     * GET /marketplace/listings/debug/duplicates?sku=2658745603
     */
    #[Route('/duplicates', name: 'marketplace_listings_debug_duplicates', methods: ['GET'])]
    public function duplicates(Request $request): JsonResponse
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $filterSku = trim((string) $request->query->get('sku', ''));

        // ── 1. Статус миграции ──────────────────────────────────────────────
        $migration = $this->connection->fetchAssociative(
            "SELECT version, executed_at::text, execution_time
             FROM doctrine_migration_versions
             WHERE version LIKE '%20260331120000%'
             LIMIT 1"
        );

        // ── 2. Уникальный индекс ────────────────────────────────────────────
        $index = $this->connection->fetchAssociative(
            "SELECT indexname, indexdef
             FROM pg_indexes
             WHERE tablename = 'marketplace_listings'
               AND indexname = 'uniq_company_marketplace_sku_size'"
        );

        // ── 3. Все дублирующие группы для компании ──────────────────────────
        $duplicateGroups = $this->connection->fetchAllAssociative(
            'SELECT
                marketplace,
                marketplace_sku,
                size,
                COUNT(*)          AS cnt,
                MIN(created_at)::text AS oldest,
                MAX(created_at)::text AS newest,
                array_agg(id ORDER BY created_at ASC)::text AS listing_ids
             FROM marketplace_listings
             WHERE company_id = :companyId
             GROUP BY marketplace, marketplace_sku, size
             HAVING COUNT(*) > 1
             ORDER BY cnt DESC, newest DESC
             LIMIT 50',
            ['companyId' => $companyId]
        );

        // ── 4. Диагностика конкретного SKU (если передан ?sku=) ─────────────
        $skuDetail = null;
        if ($filterSku !== '') {
            // Все листинги с этим SKU
            $skuListings = $this->connection->fetchAllAssociative(
                'SELECT
                    id,
                    marketplace_sku,
                    supplier_sku,
                    size,
                    name,
                    price,
                    created_at::text,
                    updated_at::text
                 FROM marketplace_listings
                 WHERE company_id  = :companyId
                   AND marketplace_sku = :sku
                 ORDER BY created_at ASC',
                ['companyId' => $companyId, 'sku' => $filterSku]
            );

            // Записи себестоимости для этих листингов
            $listingIds = array_column($skuListings, 'id');
            $costPrices = [];
            if (!empty($listingIds)) {
                $placeholders = implode(',', array_map(static fn($i) => ':id' . $i, array_keys($listingIds)));
                $params       = ['companyId' => $companyId];
                foreach ($listingIds as $i => $id) {
                    $params['id' . $i] = $id;
                }

                $costPrices = $this->connection->fetchAllAssociative(
                    "SELECT
                        icp.id,
                        icp.listing_id,
                        icp.price_amount::text,
                        icp.effective_from::text,
                        icp.effective_to::text,
                        icp.note
                     FROM marketplace_inventory_cost_prices icp
                     WHERE icp.listing_id IN ({$placeholders})
                       AND icp.company_id = :companyId
                     ORDER BY icp.effective_from ASC",
                    $params
                );
            }

            // Продажи — сколько записей привязано к каждому listing_id
            $salesPerListing = $this->connection->fetchAllAssociative(
                'SELECT listing_id, COUNT(*) AS sales_count
                 FROM marketplace_sales
                 WHERE listing_id = ANY(:ids)
                 GROUP BY listing_id',
                ['ids' => '{' . implode(',', array_map(static fn($id) => '"' . $id . '"', $listingIds)) . '}']
            );

            $skuDetail = [
                'sku'                     => $filterSku,
                'listings_count'          => count($skuListings),
                'is_duplicate'            => count($skuListings) > 1,
                'cost_prices_same_listing'=> count(array_unique(array_column($costPrices, 'listing_id'))) <= 1,
                'listings'                => $skuListings,
                'cost_prices'             => $costPrices,
                'sales_per_listing'       => $salesPerListing,
                'conclusion'              => $this->buildConclusion($skuListings, $costPrices),
            ];
        }

        return $this->json([
            'meta' => [
                'company_id'   => $companyId,
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'filter_sku'   => $filterSku ?: null,
            ],
            'migration_status' => [
                'applied'      => $migration !== false,
                'executed_at'  => $migration['executed_at'] ?? null,
                'duration_ms'  => $migration['execution_time'] ?? null,
            ],
            'unique_index' => [
                'exists'       => $index !== false,
                'definition'   => $index['indexdef'] ?? null,
            ],
            'duplicate_groups' => [
                'total_groups'    => count($duplicateGroups),
                'has_duplicates'  => count($duplicateGroups) > 0,
                'groups'          => $duplicateGroups,
            ],
            'sku_detail' => $skuDetail,
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    private function buildConclusion(array $listings, array $costPrices): string
    {
        if (count($listings) === 0) {
            return 'Листинг с таким SKU не найден.';
        }

        if (count($listings) === 1) {
            $uniqueListingIds = array_unique(array_column($costPrices, 'listing_id'));
            if (count($costPrices) > 1 && count($uniqueListingIds) === 1) {
                return 'ДУБЛЕЙ НЕТ. Один листинг, несколько записей себестоимости — это история изменения цены. Всё корректно.';
            }

            return 'ДУБЛЕЙ НЕТ. Один листинг, ' . count($costPrices) . ' запис(ей) себестоимости.';
        }

        return 'ДУБЛЬ ЛИСТИНГА: ' . count($listings) . ' записи с одинаковым SKU. Миграция не убрала их — нужен ручной анализ.';
    }
}
