<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use App\MoySklad\Application\DTO\AutomaticMappingCandidate;
use App\MoySklad\Application\DTO\AutomaticMappingCandidates;
use App\MoySklad\Application\DTO\CatalogTargetDTO;
use App\MoySklad\Domain\AutomaticListingMappingPolicy;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class AutomaticMappingCandidatesQuery
{
    private const TARGETS = <<<'SQL'
        SELECT 'product' AS type, p.external_id, NULL::uuid AS parent_external_id,
            NULL::text AS parent_article, p.name, p.code, p.article, '[]'::jsonb AS characteristics,
            p.archived, FALSE AS parent_archived, p.variants_count,
            EXISTS(SELECT 1 FROM moysklad_variants child WHERE child.company_id=p.company_id
                AND child.connection_id=p.connection_id AND child.product_external_id=p.external_id) AS has_variants
        FROM moysklad_products p WHERE p.company_id=:company AND p.connection_id=:connection
        UNION ALL
        SELECT 'variant' AS type, v.external_id, v.product_external_id AS parent_external_id,
            p.article AS parent_article, v.name, v.code, v.article, v.characteristics,
            v.archived, p.archived AS parent_archived, 0 AS variants_count, FALSE AS has_variants
        FROM moysklad_variants v JOIN moysklad_products p ON p.company_id=v.company_id
            AND p.connection_id=v.connection_id AND p.external_id=v.product_external_id
        WHERE v.company_id=:company AND v.connection_id=:connection
        SQL;

    public function __construct(private Connection $db)
    {
    }

    /** @param list<string> $articles
     * @return array<string,AutomaticMappingCandidates> keyed by article:<normalized key>
     */
    public function forArticles(string $companyId, string $connectionId, array $articles): array
    {
        Assert::maxCount($articles, 100);
        $companyId = Uuid::fromString($companyId)->toString();
        $connectionId = Uuid::fromString($connectionId)->toString();
        $normalized = [];
        foreach ($articles as $article) {
            Assert::maxLength($article, 255);
            $value = AutomaticListingMappingPolicy::normalizeKey($article);
            if (null !== $value) {
                $normalized['article:'.$value] = $value;
            }
        }
        if ([] === $normalized) {
            return [];
        }
        $params = ['company' => $companyId, 'connection' => $connectionId, 'trim' => AutomaticListingMappingPolicy::TRIM_CHARACTERS];
        $values = [];
        foreach (array_values($normalized) as $i => $article) {
            $params['a'.$i] = $article;
            $values[] = '(CAST(:a'.$i.' AS TEXT))';
        }
        $fields = 'type, external_id, parent_external_id, parent_article, name, code, article, characteristics, archived, parent_archived, variants_count, has_variants';
        $targetFields = 't.'.str_replace(', ', ', t.', $fields);
        $sql = 'WITH requested(article) AS (VALUES '.implode(',', $values).'), targets AS ('.self::TARGETS.'), ranked AS (
            SELECT r.article AS matched_article, '.$targetFields.',
                COUNT(*) OVER (PARTITION BY r.article COLLATE "C") AS total_count,
                ROW_NUMBER() OVER (PARTITION BY r.article COLLATE "C" ORDER BY t.type, t.external_id) AS position
            FROM requested r JOIN targets t ON btrim(t.article,:trim) COLLATE "C"=r.article COLLATE "C"
                OR (t.type=\'variant\' AND btrim(t.parent_article,:trim) COLLATE "C"=r.article COLLATE "C")
        ) SELECT matched_article, '.$fields.', total_count, position FROM ranked WHERE position<=51 ORDER BY matched_article, type, external_id';
        $rows = $this->db->fetchAllAssociative($sql, $params);
        $items = array_fill_keys(array_keys($normalized), []);
        $counts = array_fill_keys(array_keys($normalized), 0);
        foreach ($rows as $row) {
            $key = 'article:'.$row['matched_article'];
            $counts[$key] = (int) $row['total_count'];
            if (count($items[$key]) < 50) {
                $leaf = 'variant' === $row['type'] || (0 === (int) $row['variants_count'] && !(bool) $row['has_variants']);
                $items[$key][] = new AutomaticMappingCandidate(CatalogTargetDTO::fromRow($row), null === $row['parent_article'] ? null : (string) $row['parent_article'], $leaf);
            }
        }
        $result = [];
        foreach ($items as $key => $group) {
            $result[$key] = new AutomaticMappingCandidates($group, $counts[$key], $counts[$key] > 50);
        }

        return $result;
    }
}
