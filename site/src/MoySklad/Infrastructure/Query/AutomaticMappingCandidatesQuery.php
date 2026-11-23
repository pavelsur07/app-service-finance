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

    public function __construct(private Connection $db, private \App\Marketplace\Facade\MarketplaceFacade $marketplace)
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
        $linked = $this->linkedTargets($companyId, $connectionId, $listings, $barcodes);
        $values = [];
        foreach ($listings as $i => $listing) {
            $params['id'.$i] = $listing->id;
            $params['article'.$i] = AutomaticListingMappingPolicy::normalizeKey($listing->supplierSku);
            $params['alias'.$i] = AutomaticListingMappingPolicy::articleAlias($listing, $wbTrailingW);
            $params['barcode'.$i] = json_encode($barcodes[$listing->id] ?? [], \JSON_THROW_ON_ERROR);
            $params['sized'.$i] = json_encode(AutomaticListingMappingPolicy::articleSizeKeys($listing->supplierSku, $listing->size), \JSON_THROW_ON_ERROR);
            $params['linked'.$i] = json_encode($linked[$listing->id] ?? [], \JSON_THROW_ON_ERROR);
            $values[] = '(CAST(:id'.$i.' AS TEXT), CAST(:article'.$i.' AS TEXT), CAST(:alias'.$i.' AS TEXT), CAST(:barcode'.$i.' AS JSONB), CAST(:sized'.$i.' AS JSONB), CAST(:linked'.$i.' AS JSONB))';
        }
        $params['cyr'] = implode('', array_keys(AutomaticListingMappingPolicy::CYRILLIC_AS_LATIN));
        $params['lat'] = implode('', AutomaticListingMappingPolicy::CYRILLIC_AS_LATIN);
        $rows = $this->db->fetchAllAssociative('WITH requested(id, article, alias, barcodes, sized, linked) AS (VALUES '.implode(',', $values).'), targets AS ('.self::TARGETS.')
            SELECT r.id AS listing_id, t.type, t.external_id, t.parent_external_id, t.parent_article, t.name, t.code, t.article, t.barcodes, t.characteristics, t.archived, t.parent_archived, t.variants_count, t.has_variants, t.total_count, t.linked_barcode, t.linked_elsewhere FROM requested r CROSS JOIN LATERAL (
                SELECT t.type, t.external_id, t.parent_external_id, t.parent_article, t.name, t.code, t.article, t.barcodes, t.characteristics, t.archived, t.parent_archived, t.variants_count, t.has_variants, COUNT(*) OVER() AS total_count,
                    jsonb_exists(r.linked, t.type || \':\' || t.external_id::text) AS linked_barcode,
                    EXISTS(SELECT 1 FROM moysklad_listing_mapping_revisions m WHERE m.company_id=:company AND m.connection_id=:connection AND m.operation=\'set\'
                        AND COALESCE(m.variant_external_id, m.product_external_id)=t.external_id
                        AND NOT EXISTS(SELECT 1 FROM moysklad_listing_mapping_revisions n WHERE n.company_id=m.company_id AND n.connection_id=m.connection_id AND n.listing_id=m.listing_id AND n.revision>m.revision)) AS linked_elsewhere
                FROM targets t
                WHERE btrim(t.article,:trim) COLLATE "C"=r.article COLLATE "C"
                    OR btrim(t.code,:trim) COLLATE "C"=r.article COLLATE "C"
                    OR (t.type=\'variant\' AND btrim(t.parent_article,:trim) COLLATE "C"=r.article COLLATE "C")
                    OR btrim(t.article,:trim) COLLATE "C"=r.alias COLLATE "C"
                    OR (t.type=\'variant\' AND btrim(t.parent_article,:trim) COLLATE "C"=r.alias COLLATE "C")
                    OR jsonb_exists_any(t.barcodes, ARRAY(SELECT jsonb_array_elements_text(r.barcodes)))
                    OR translate(upper(btrim(t.article,:trim)), :cyr, :lat) = ANY(ARRAY(SELECT jsonb_array_elements_text(r.sized)))
                    OR jsonb_exists(r.linked, t.type || \':\' || t.external_id::text)
                ORDER BY t.type, t.external_id LIMIT 50
            ) t ORDER BY r.id, t.type, t.external_id', $params);
        $items = $counts = [];
        foreach ($rows as $row) {
            $id = (string) $row['listing_id'];
            $counts[$id] = (int) $row['total_count'];
            $leaf = 'variant' === $row['type'] || (0 === (int) $row['variants_count'] && !(bool) $row['has_variants']);
            $items[$id][] = new AutomaticMappingCandidate(CatalogTargetDTO::fromRow($row), null === $row['parent_article'] ? null : (string) $row['parent_article'], $leaf,
                (bool) $row['linked_barcode'], (bool) $row['linked_elsewhere']);
        }
        $result = [];
        foreach ($listings as $listing) {
            $count = $counts[$listing->id] ?? 0;
            $result[$listing->id] = new AutomaticMappingCandidates($items[$listing->id] ?? [], $count, $count > 50);
        }

        return $result;
    }

    /**
     * Штрихкод листинга → листинги других площадок с тем же штрихкодом → их текущая связь (SET) в этом подключении.
     *
     * @param list<\App\Marketplace\DTO\ListingMetaDTO> $listings
     * @param array<string,list<string>> $barcodes
     *
     * @return array<string,list<string>> listingId => ['product:<uuid>' | 'variant:<uuid>', ...]
     */
    private function linkedTargets(string $companyId, string $connectionId, array $listings, array $barcodes): array
    {
        $byMarketplace = [];
        foreach ($listings as $listing) {
            foreach ($barcodes[$listing->id] ?? [] as $barcode) {
                $byMarketplace[$listing->marketplace][] = $barcode;
            }
        }
        $others = [];
        foreach ($byMarketplace as $marketplace => $codes) {
            $others[$marketplace] = $this->marketplace->getListingIdsByBarcodes($companyId, $codes, $marketplace);
        }
        $ids = [];
        foreach ($others as $map) {
            foreach ($map as $listingIds) {
                foreach ($listingIds as $id) {
                    $ids[$id] = true;
                }
            }
        }
        $ids = array_keys($ids);
        if ([] === $ids) {
            return [];
        }
        $targets = $this->db->fetchAllKeyValue("SELECT DISTINCT ON (listing_id) listing_id::text, CASE WHEN operation='set' THEN CASE WHEN variant_external_id IS NULL THEN 'product:' || product_external_id::text ELSE 'variant:' || variant_external_id::text END END
            FROM moysklad_listing_mapping_revisions WHERE company_id=? AND connection_id=? AND listing_id IN (?) ORDER BY listing_id, revision DESC",
            [$companyId, $connectionId, $ids], [2 => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $result = [];
        foreach ($listings as $listing) {
            foreach ($barcodes[$listing->id] ?? [] as $barcode) {
                foreach ($others[$listing->marketplace][$barcode] ?? [] as $other) {
                    if (null !== ($targets[$other] ?? null)) {
                        $result[$listing->id][] = (string) $targets[$other];
                    }
                }
            }
            if (isset($result[$listing->id])) {
                $result[$listing->id] = array_values(array_unique($result[$listing->id]));
            }
        }

        return $result;
    }
}
