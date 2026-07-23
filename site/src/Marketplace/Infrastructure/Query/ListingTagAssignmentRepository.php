<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\DTO\ListingTagDTO;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Связи «листинг ↔ тег». Таблица без ORM-маппинга: все операции здесь массовые,
 * а Doctrine на составном ключе дал бы N сущностей вместо одного запроса.
 */
final readonly class ListingTagAssignmentRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Назначает тег листингам компании.
     *
     * Принадлежность компании проверяется внутри SQL: листинги чужой компании
     * не проходят WHERE и молча не назначаются. Повтор гасится ON CONFLICT.
     *
     * @param list<string> $listingIds
     *
     * @return int сколько связей реально создано (может быть меньше count($listingIds))
     */
    public function assign(string $companyId, array $listingIds, string $tagId): int
    {
        if ([] === $listingIds) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at)
            SELECT l.id, :tagId, :companyId, NOW()
            FROM marketplace_listings l
            WHERE l.id IN (:listingIds)
              AND l.company_id = :companyId
            ON CONFLICT DO NOTHING
            SQL,
            [
                'tagId' => $tagId,
                'companyId' => $companyId,
                'listingIds' => $listingIds,
            ],
            ['listingIds' => ArrayParameterType::STRING],
        );
    }

    /**
     * @param list<string> $listingIds
     *
     * @return int сколько связей реально снято
     */
    public function detach(string $companyId, array $listingIds, string $tagId): int
    {
        if ([] === $listingIds) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM marketplace_listing_tag_assignments
            WHERE company_id = :companyId
              AND tag_id = :tagId
              AND listing_id IN (:listingIds)
            SQL,
            [
                'companyId' => $companyId,
                'tagId' => $tagId,
                'listingIds' => $listingIds,
            ],
            ['listingIds' => ArrayParameterType::STRING],
        );
    }

    /**
     * Листинги компании, у которых есть заданные теги.
     *
     * @param list<string> $tagIds
     *
     * @return list<string> listingId
     */
    public function listingIdsByTags(string $companyId, array $tagIds, bool $matchAll): array
    {
        $tagIds = array_values(array_unique($tagIds));
        if ([] === $tagIds) {
            return [];
        }

        // matchAll: листинг должен нести ВСЕ выбранные теги — считаем совпавшие теги
        // и сравниваем с их числом. any: достаточно одного, DISTINCT снимает дубли.
        $sql = $matchAll
            ? <<<'SQL'
                SELECT listing_id
                FROM marketplace_listing_tag_assignments
                WHERE company_id = :companyId
                  AND tag_id IN (:tagIds)
                GROUP BY listing_id
                HAVING COUNT(DISTINCT tag_id) = :tagCount
                SQL
            : <<<'SQL'
                SELECT DISTINCT listing_id
                FROM marketplace_listing_tag_assignments
                WHERE company_id = :companyId
                  AND tag_id IN (:tagIds)
                SQL;

        $params = ['companyId' => $companyId, 'tagIds' => $tagIds];
        $types = ['tagIds' => ArrayParameterType::STRING];

        if ($matchAll) {
            $params['tagCount'] = count($tagIds);
            $types['tagCount'] = ParameterType::INTEGER;
        }

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn($sql, $params, $types);

        return $ids;
    }

    /**
     * Один запрос на всю страницу реестра — иначе колонка тегов даёт N+1.
     *
     * @param list<string> $listingIds
     *
     * @return array<string, list<ListingTagDTO>> ключ — listingId
     */
    public function tagsForListings(string $companyId, array $listingIds): array
    {
        if ([] === $listingIds) {
            return [];
        }

        /** @var list<array{listing_id: string, id: string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT a.listing_id, t.id, t.name
            FROM marketplace_listing_tag_assignments a
            JOIN marketplace_listing_tags t ON t.id = a.tag_id
            WHERE a.company_id = :companyId
              AND a.listing_id IN (:listingIds)
            ORDER BY t.name
            SQL,
            [
                'companyId' => $companyId,
                'listingIds' => $listingIds,
            ],
            ['listingIds' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['listing_id']][] = new ListingTagDTO($row['id'], $row['name']);
        }

        return $result;
    }
}
