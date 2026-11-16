<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use App\MoySklad\Application\DTO\AutomaticMappingCandidate;
use App\MoySklad\Application\DTO\AutomaticMappingCandidates;
use App\MoySklad\Application\DTO\CatalogTargetDTO;
use App\MoySklad\Domain\AutomaticListingMappingPolicy;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

final readonly class AutomaticMappingCandidatesQuery
{
    private const TARGETS = <<<'SQL'
        SELECT 'product' AS type, p.external_id, NULL::uuid AS parent_external_id,
            NULL::text AS parent_article, p.name, p.code, p.article, p.barcodes, '[]'::jsonb AS characteristics,
            p.archived, FALSE AS parent_archived, p.variants_count,
            EXISTS(SELECT 1 FROM moysklad_variants child WHERE child.company_id=p.company_id
                AND child.connection_id=p.connection_id AND child.product_external_id=p.external_id) AS has_variants
        FROM moysklad_products p WHERE p.company_id=:company AND p.connection_id=:connection
        UNION ALL
        SELECT 'variant' AS type, v.external_id, v.product_external_id AS parent_external_id,
            p.article AS parent_article, v.name, v.code, v.article, v.barcodes, v.characteristics,
            v.archived, p.archived AS parent_archived, 0 AS variants_count, FALSE AS has_variants
        FROM moysklad_variants v JOIN moysklad_products p ON p.company_id=v.company_id
            AND p.connection_id=v.connection_id AND p.external_id=v.product_external_id
        WHERE v.company_id=:company AND v.connection_id=:connection
        SQL;

    public function __construct(private Connection $db)
    {
    }

    /** @param list<\App\Marketplace\DTO\ListingMetaDTO> $listings
     * @param array<string,list<string>> $barcodes
     *
     * @return array<string,AutomaticMappingCandidates> keyed by listing ID
     */
    public function forListings(string $companyId, string $connectionId, array $listings, array $barcodes, bool $wbTrailingW): array
    {
        Assert::maxCount($listings, 100);
        if ([] === $listings) {
            return [];
        }
        $params = ['company' => $companyId, 'connection' => $connectionId, 'trim' => AutomaticListingMappingPolicy::TRIM_CHARACTERS];
        $values = [];
        foreach ($listings as $i => $listing) {
            $params['id'.$i] = $listing->id;
            $params['article'.$i] = AutomaticListingMappingPolicy::normalizeKey($listing->supplierSku);
            $params['alias'.$i] = AutomaticListingMappingPolicy::articleAlias($listing, $wbTrailingW);
            $params['barcode'.$i] = json_encode($barcodes[$listing->id] ?? [], \JSON_THROW_ON_ERROR);
            $values[] = '(CAST(:id'.$i.' AS TEXT), CAST(:article'.$i.' AS TEXT), CAST(:alias'.$i.' AS TEXT), CAST(:barcode'.$i.' AS JSONB))';
        }
        $rows = $this->db->fetchAllAssociative('WITH requested(id, article, alias, barcodes) AS (VALUES '.implode(',', $values).'), targets AS ('.self::TARGETS.')
            SELECT r.id AS listing_id, t.type, t.external_id, t.parent_external_id, t.parent_article, t.name, t.code, t.article, t.barcodes, t.characteristics, t.archived, t.parent_archived, t.variants_count, t.has_variants, t.total_count FROM requested r CROSS JOIN LATERAL (
                SELECT t.type, t.external_id, t.parent_external_id, t.parent_article, t.name, t.code, t.article, t.barcodes, t.characteristics, t.archived, t.parent_archived, t.variants_count, t.has_variants, COUNT(*) OVER() AS total_count FROM targets t
                WHERE btrim(t.article,:trim) COLLATE "C"=r.article COLLATE "C"
                    OR btrim(t.code,:trim) COLLATE "C"=r.article COLLATE "C"
                    OR (t.type=\'variant\' AND btrim(t.parent_article,:trim) COLLATE "C"=r.article COLLATE "C")
                    OR btrim(t.article,:trim) COLLATE "C"=r.alias COLLATE "C"
                    OR (t.type=\'variant\' AND btrim(t.parent_article,:trim) COLLATE "C"=r.alias COLLATE "C")
                    OR jsonb_exists_any(t.barcodes, ARRAY(SELECT jsonb_array_elements_text(r.barcodes)))
                ORDER BY t.type, t.external_id LIMIT 50
            ) t ORDER BY r.id, t.type, t.external_id', $params);
        $items = $counts = [];
        foreach ($rows as $row) {
            $id = (string) $row['listing_id'];
            $counts[$id] = (int) $row['total_count'];
            $leaf = 'variant' === $row['type'] || (0 === (int) $row['variants_count'] && !(bool) $row['has_variants']);
            $items[$id][] = new AutomaticMappingCandidate(CatalogTargetDTO::fromRow($row), null === $row['parent_article'] ? null : (string) $row['parent_article'], $leaf);
        }
        $result = [];
        foreach ($listings as $listing) {
            $count = $counts[$listing->id] ?? 0;
            $result[$listing->id] = new AutomaticMappingCandidates($items[$listing->id] ?? [], $count, $count > 50);
        }

        return $result;
    }
}
