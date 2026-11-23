<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\DTO\ListingMetaDTO;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class ListingMetaQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** Caller owns a transaction; lock before the fresh DBAL metadata read. */
    public function lockForExternalMapping(string $companyId, string $listingId): ?ListingMetaDTO
    {
        \Webmozart\Assert\Assert::uuid($companyId);
        \Webmozart\Assert\Assert::uuid($listingId);
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Listing lock requires a caller transaction.');
        }
        if (false === $this->connection->fetchOne('SELECT id FROM marketplace_listings WHERE company_id=? AND id=? FOR UPDATE', [$companyId, $listingId])) {
            return null;
        }

        $this->connection->fetchFirstColumn('SELECT id FROM marketplace_listing_barcodes WHERE company_id=? AND listing_id=? ORDER BY id FOR SHARE', [$companyId, $listingId]);

        return $this->findByIds($companyId, [strtolower($listingId)])[strtolower($listingId)] ?? null;
    }

    /**
     * @param list<string> $listingIds
     *
     * @return array<string, ListingMetaDTO> keyed by id
     */
    public function findByIds(string $companyId, array $listingIds): array
    {
        if ([] === $listingIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                l.id,
                l.name            AS listing_title,
                l.marketplace_sku AS listing_sku,
                l.marketplace     AS listing_marketplace,
                l.supplier_sku    AS supplier_sku,
                l.size            AS listing_size,
                l.is_active,
                l.marketplace_variant_id
            FROM marketplace_listings l
            WHERE l.id IN (:ids)
              AND l.company_id = :companyId
            SQL,
            [
                'ids' => array_values($listingIds),
                'companyId' => $companyId,
            ],
            ['ids' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = new ListingMetaDTO(
                id: $row['id'],
                title: $row['listing_title'],
                sku: $row['listing_sku'],
                marketplace: $row['listing_marketplace'],
                supplierSku: null !== $row['supplier_sku'] ? (string) $row['supplier_sku'] : null,
                size: in_array($row['listing_size'], [null, '', 'UNKNOWN'], true) ? null : (string) $row['listing_size'],
                isActive: (bool) $row['is_active'],
                marketplaceVariantId: $row['marketplace_variant_id'],
            );
        }

        return $result;
    }
}
