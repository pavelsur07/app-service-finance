<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\ListingCategorySource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Массовые операции с `marketplace_listings.category_id`. Область компании —
 * в каждом SQL (`company_id = :companyId`): чужие id молча не попадают в выборку.
 * Запись не коммитит — транзакцией владеет вызывающий Action.
 */
final readonly class ListingCategoryAssignmentQuery
{
    private const READ_CHUNK = 5000;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Ставит категорию (или снимает при null). Строки, где категория и источник
     * уже такие, не трогаются — повтор возвращает 0.
     *
     * @param list<string> $listingIds
     *
     * @return int сколько листингов изменилось
     */
    public function assign(string $companyId, array $listingIds, ?string $categoryId, ListingCategorySource $source): int
    {
        if ([] === $listingIds) {
            return 0;
        }

        $sourceValue = null === $categoryId ? null : $source->value;

        return (int) $this->connection->executeStatement(
            'UPDATE marketplace_listings
                SET category_id = CAST(:categoryId AS UUID), category_source = CAST(:source AS VARCHAR)
              WHERE company_id = :companyId
                AND id IN (:listingIds)
                AND (category_id IS DISTINCT FROM CAST(:categoryId AS UUID)
                     OR category_source IS DISTINCT FROM CAST(:source AS VARCHAR))',
            [
                'categoryId' => $categoryId,
                'source' => $sourceValue,
                'companyId' => $companyId,
                'listingIds' => $listingIds,
            ],
            ['listingIds' => ArrayParameterType::STRING],
        );
    }

    /**
     * @param list<string> $listingIds
     */
    public function countCompanyListings(string $companyId, array $listingIds): int
    {
        if ([] === $listingIds) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listings WHERE company_id = :companyId AND id IN (:listingIds)',
            ['companyId' => $companyId, 'listingIds' => $listingIds],
            ['listingIds' => ArrayParameterType::STRING],
        );
    }

    public function countByCategory(string $companyId, string $categoryId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listings WHERE company_id = :companyId AND category_id = :categoryId',
            ['companyId' => $companyId, 'categoryId' => $categoryId],
        );
    }

    /**
     * @return array<string, int> categoryId → число листингов
     */
    public function countsByCategory(string $companyId): array
    {
        /** @var array<string, int|string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT category_id, COUNT(*) FROM marketplace_listings
              WHERE company_id = :companyId AND category_id IS NOT NULL GROUP BY category_id',
            ['companyId' => $companyId],
        );

        return array_map('intval', $rows);
    }

    /**
     * @param list<string> $listingIds
     *
     * @return array<string, string> listingId → categoryId; листинги без категории не попадают
     */
    public function categoriesForListings(string $companyId, array $listingIds): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($listingIds)), self::READ_CHUNK) as $chunk) {
            /** @var array<string, string> $rows */
            $rows = $this->connection->fetchAllKeyValue(
                'SELECT id, category_id FROM marketplace_listings
                  WHERE company_id = :companyId AND id IN (:listingIds) AND category_id IS NOT NULL',
                ['companyId' => $companyId, 'listingIds' => $chunk],
                ['listingIds' => ArrayParameterType::STRING],
            );
            $result += $rows;
        }

        return $result;
    }
}
