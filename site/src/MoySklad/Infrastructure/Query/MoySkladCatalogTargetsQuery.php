<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use App\MoySklad\Application\DTO\CatalogTargetDTO;
use App\MoySklad\Application\DTO\CatalogTargetPage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class MoySkladCatalogTargetsQuery
{
    private const TARGETS = <<<'SQL'
        SELECT 'product' AS type, p.external_id, NULL::uuid AS parent_external_id,
            p.name, p.code, p.article, p.barcodes, '[]'::jsonb AS characteristics, p.archived,
            FALSE AS parent_archived, p.variants_count,
            EXISTS(SELECT 1 FROM moysklad_variants child WHERE child.company_id=p.company_id AND child.connection_id=p.connection_id AND child.product_external_id=p.external_id) AS has_variants
        FROM moysklad_products p WHERE p.company_id=:company AND p.connection_id=:connection
        UNION ALL
        SELECT 'variant' AS type, v.external_id, v.product_external_id AS parent_external_id,
            v.name, v.code, v.article, v.barcodes, v.characteristics, v.archived,
            p.archived AS parent_archived, 0 AS variants_count, FALSE AS has_variants
        FROM moysklad_variants v JOIN moysklad_products p
            ON p.company_id=v.company_id AND p.connection_id=v.connection_id AND p.external_id=v.product_external_id
        WHERE v.company_id=:company AND v.connection_id=:connection
        SQL;
    private const SELECTABLE = 'NOT archived AND NOT parent_archived AND (type = \'variant\' OR (variants_count = 0 AND NOT has_variants))';

    public function __construct(private Connection $db)
    {
    }

    public function isReady(string $companyId, string $connectionId): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT EXISTS(SELECT 1 FROM moysklad_connections c WHERE c.company_id=:company AND c.id=:connection AND c.is_active AND c.account_id IS NOT NULL AND
                (SELECT COUNT(*) FROM moysklad_sync_cursors cur WHERE cur.company_id=c.company_id AND cur.connection_id=c.id AND cur.entity_type IN ('product','variant') AND cur.last_completed_at IS NOT NULL)=2)",
            ['company' => $companyId, 'connection' => $connectionId],
        );
    }

    public function search(string $companyId, string $connectionId, string $q, int $page = 1): CatalogTargetPage
    {
        Assert::greaterThanEq($page, 1);
        $q = trim($q);
        Assert::lessThanEq(mb_strlen($q), 100);
        if (mb_strlen($q) < 2) {
            return new CatalogTargetPage([], $page, 0);
        }

        return $this->page($companyId, $connectionId, self::SELECTABLE.' AND (LOWER(name) LIKE :q OR LOWER(code) LIKE :q OR LOWER(article) LIKE :q OR jsonb_exists(barcodes, :barcode))', ['barcode' => $q, 'q' => '%'.addcslashes(mb_strtolower($q), '%_\\').'%'], $page);
    }

    public function suggest(string $companyId, string $connectionId, ?string $supplierSku): CatalogTargetPage
    {
        $article = trim($supplierSku ?? '');
        if ('' === $article) {
            return new CatalogTargetPage([], 1, 0);
        }

        return $this->page($companyId, $connectionId, self::SELECTABLE.' AND TRIM(article)=:article', ['article' => $article], 1);
    }

    public function find(string $companyId, string $connectionId, string $type, string $externalId): ?CatalogTargetDTO
    {
        $externalId = Uuid::fromString($externalId)->toString();
        $items = $this->findMany($companyId, $connectionId, [$type.':'.$externalId]);

        return $items[$type.':'.$externalId] ?? null;
    }

    /** @param list<string> $references
     * @return array<string,CatalogTargetDTO>
     */
    public function findMany(string $companyId, string $connectionId, array $references): array
    {
        Assert::maxCount($references, 100);
        if ([] === $references) {
            return [];
        }
        $ids = ['product' => [], 'variant' => []];
        foreach ($references as $reference) {
            $parts = explode(':', $reference, 2);
            Assert::count($parts, 2);
            Assert::keyExists($ids, $parts[0]);
            $ids[$parts[0]][] = Uuid::fromString($parts[1])->toString();
        }
        $rows = $this->db->fetchAllAssociative('SELECT type, external_id, parent_external_id, name, code, article, barcodes, characteristics, archived, parent_archived, variants_count, has_variants FROM ('.self::TARGETS.") targets WHERE (type = 'product' AND external_id IN (:products)) OR (type = 'variant' AND external_id IN (:variants))",
            ['company' => $companyId, 'connection' => $connectionId, 'products' => $ids['product'], 'variants' => $ids['variant']],
            ['products' => ArrayParameterType::STRING, 'variants' => ArrayParameterType::STRING]);
        $result = [];
        foreach ($rows as $row) {
            $target = CatalogTargetDTO::fromRow($row);
            $result[$target->getValue()] = $target;
        }

        return $result;
    }

    public function lock(string $companyId, string $connectionId, string $type, string $externalId): ?CatalogTargetDTO
    {
        $params = ['company' => $companyId, 'connection' => $connectionId, 'id' => $externalId];
        if ('product' === $type) {
            $this->db->fetchOne('SELECT external_id FROM moysklad_products WHERE company_id=:company AND connection_id=:connection AND external_id=:id FOR UPDATE', $params);
        } elseif ('variant' === $type) {
            $parent = $this->db->fetchOne('SELECT product_external_id FROM moysklad_variants WHERE company_id=:company AND connection_id=:connection AND external_id=:id', $params);
            if (false === $parent) {
                return null;
            }
            $this->db->fetchOne('SELECT external_id FROM moysklad_products WHERE company_id=:company AND connection_id=:connection AND external_id=:id FOR SHARE', array_replace($params, ['id' => $parent]));
            $actualParent = $this->db->fetchOne('SELECT product_external_id FROM moysklad_variants WHERE company_id=:company AND connection_id=:connection AND external_id=:id FOR SHARE', $params);
            if ($parent !== $actualParent) {
                return null;
            }
        } else {
            return null;
        }

        return $this->find($companyId, $connectionId, $type, $externalId);
    }

    /** @param array<string,string> $extra */
    private function page(string $companyId, string $connectionId, string $where, array $extra, int $page): CatalogTargetPage
    {
        $params = ['company' => $companyId, 'connection' => $connectionId] + $extra;
        $sql = ' FROM ('.self::TARGETS.') targets WHERE '.$where;
        $total = (int) $this->db->fetchOne('SELECT COUNT(*)'.$sql, $params);
        if ($page > max(1, (int) ceil($total / 20))) {
            return new CatalogTargetPage([], $page, $total);
        }
        $rows = $this->db->fetchAllAssociative('SELECT type, external_id, parent_external_id, name, code, article, barcodes, characteristics, archived, parent_archived, variants_count, has_variants'.$sql.' ORDER BY name, type, external_id LIMIT 20 OFFSET '.(($page - 1) * 20), $params);

        return new CatalogTargetPage(array_map(CatalogTargetDTO::fromRow(...), $rows), $page, $total);
    }
}
