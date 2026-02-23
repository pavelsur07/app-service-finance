<?php

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Query для получения списка листингов без привязки к Product
 */
class UnmappedListingsQuery
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Получить все unmapped листинги для компании
     *
     * @return array<int, array{
     *   id: string,
     *   nm_id: string,
     *   sku: string,
     *   name: string,
     *   marketplace: string,
     *   barcode: string|null,
     *   created_at: string
     * }>
     */
    public function fetchAllForCompany(string $companyId): array
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'l.id',
                'l.marketplace_sku',
                'l.supplier_sku',
                'l.marketplace',
                'l.size',
                'l.price',
                'l.created_at'
            )
            ->from('marketplace_listings', 'l')
            ->where('l.company_id = :company')
            ->andWhere('l.product_id IS NULL')
            ->orderBy('l.created_at', 'DESC')
            ->setParameter('company', $companyId)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Количество unmapped листингов для компании
     */
    public function countUnmappedForCompany(string $companyId): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from('marketplace_listings', 'l')
            ->where('l.company_id = :company')
            ->andWhere('l.product_id IS NULL')
            ->setParameter('company', $companyId)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Поиск unmapped листингов по SKU или названию
     *
     * @return array<int, array{
     *   id: string,
     *   nm_id: string,
     *   sku: string,
     *   name: string,
     *   marketplace: string,
     *   barcode: string|null
     * }>
     */
    public function searchUnmapped(string $companyId, string $search): array
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'l.id',
                'l.marketplace_sku',
                'l.supplier_sku',
                'l.marketplace',
                'l.size',
                'l.price'
            )
            ->from('marketplace_listings', 'l')
            ->where('l.company_id = :company')
            ->andWhere('l.product_id IS NULL')
            ->andWhere('(l.marketplace_sku ILIKE :search OR l.supplier_sku ILIKE :search)')
            ->orderBy('l.created_at', 'DESC')
            ->setParameter('company', $companyId)
            ->setParameter('search', '%' . $search . '%')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
