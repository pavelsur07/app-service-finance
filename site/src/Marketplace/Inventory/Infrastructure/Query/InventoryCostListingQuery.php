<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * DBAL READ-запросы для UI субмодуля Inventory.
 *
 * listingsQueryBuilder() — DBAL QueryBuilder для всех листингов компании
 *   с текущей себестоимостью и первым баркодом. Без фильтра по product_id —
 *   листинг без привязки к продукту тоже отображается.
 *
 * fetchHistory()    — история цен конкретного листинга.
 * findListingMeta() — мета-информация для заголовка страницы истории.
 */
final readonly class InventoryCostListingQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * DBAL QueryBuilder для списка листингов с текущей себестоимостью и баркодом.
     *
     * Результирующие строки:
     *   listing_id, marketplace, marketplace_sku, supplier_sku, listing_name,
     *   barcode (первый баркод или null),
     *   product_id (null если не привязан), product_name (null), product_sku (null),
     *   cost_price (null если не задана), cost_currency, cost_from
     */
    public function listingsQueryBuilder(string $companyId, ?string $marketplace): QueryBuilder
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        $qb = $this->connection->createQueryBuilder()
            ->select(
                'l.id                AS listing_id',
                'l.marketplace       AS marketplace',
                'l.marketplace_sku   AS marketplace_sku',
                'l.supplier_sku      AS supplier_sku',
                'l.name              AS listing_name',
                '(SELECT b.barcode FROM marketplace_listing_barcodes b WHERE b.listing_id = l.id AND b.company_id = l.company_id ORDER BY b.barcode LIMIT 1) AS barcode',
                'p.id                AS product_id',
                'p.name              AS product_name',
                'p.sku               AS product_sku',
                'ic.price_amount     AS cost_price',
                'ic.price_currency   AS cost_currency',
                'ic.effective_from   AS cost_from',
            )
            ->from('marketplace_listings', 'l')
            ->leftJoin('l', 'products', 'p', 'p.id = l.product_id')
            ->leftJoin(
                'l',
                // DISTINCT ON (company_id, listing_id) гарантирует одну строку на листинг
                // с последней активной ценой. Фильтр по company_id в WHERE обязателен —
                // без него подзапрос обходит записи всех компаний и возможно смешение контекстов.
                '(SELECT DISTINCT ON (company_id, listing_id)
                      listing_id, company_id, price_amount, price_currency, effective_from
                  FROM marketplace_inventory_cost_prices
                  WHERE company_id = :companyId
                    AND effective_from <= :today
                    AND (effective_to IS NULL OR effective_to >= :today)
                  ORDER BY company_id, listing_id, effective_from DESC)',
                'ic',
                'ic.listing_id = l.id AND ic.company_id = l.company_id',
            )
            ->where('l.company_id = :companyId')
            ->orderBy('l.marketplace', 'ASC')
            ->addOrderBy('l.name', 'ASC')
            ->addOrderBy('l.marketplace_sku', 'ASC')
            ->setParameter('companyId', $companyId)
            ->setParameter('today', $today);

        if ($marketplace !== null) {
            $qb->andWhere('l.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        return $qb;
    }

    /**
     * История цен для конкретного листинга.
     *
     * @return list<array{
     *     id: string,
     *     effective_from: string,
     *     effective_to: string|null,
     *     price_amount: string,
     *     price_currency: string,
     *     note: string|null,
     *     created_at: string,
     * }>
     */
    public function fetchHistory(string $companyId, string $listingId, int $limit = 50): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, effective_from, effective_to, price_amount, price_currency, note, created_at
             FROM marketplace_inventory_cost_prices
             WHERE company_id = :companyId
               AND listing_id = :listingId
             ORDER BY effective_from DESC
             LIMIT :limit',
            [
                'companyId' => $companyId,
                'listingId' => $listingId,
                'limit'     => $limit,
            ],
            ['limit' => \PDO::PARAM_INT],
        );
    }

    /**
     * Мета-информация листинга для заголовка страницы истории.
     *
     * @return array{
     *     listing_id: string,
     *     marketplace: string,
     *     marketplace_sku: string,
     *     listing_name: string|null,
     *     product_id: string|null,
     *     product_name: string|null,
     *     product_sku: string|null,
     * }|null
     */
    public function findListingMeta(string $companyId, string $listingId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                l.id              AS listing_id,
                l.marketplace     AS marketplace,
                l.marketplace_sku AS marketplace_sku,
                l.name            AS listing_name,
                p.id              AS product_id,
                p.name            AS product_name,
                p.sku             AS product_sku
             FROM marketplace_listings l
             LEFT JOIN products p ON p.id = l.product_id
             WHERE l.id = :listingId
               AND l.company_id = :companyId
             LIMIT 1',
            ['listingId' => $listingId, 'companyId' => $companyId],
        );

        return $row ?: null;
    }
}
