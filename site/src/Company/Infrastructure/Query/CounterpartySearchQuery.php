<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Query;

use App\Company\Domain\Service\CounterpartyNameNormalizer;
use Doctrine\DBAL\Connection;

/**
 * Поиск контрагента по ИНН или названию для автокомплита.
 *
 * Скалярный read-модель-запрос: Entity не гидрируем. Запрос пользователя проходит
 * через тот же нормализатор, что и сохранённое название, иначе «"Ромашка" ООО»
 * не найдётся по «ромашка».
 */
final class CounterpartySearchQuery
{
    private const SIMILARITY_THRESHOLD = 0.3;
    private const MAX_LIMIT = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly CounterpartyNameNormalizer $normalizer,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, inn: ?string, kpp: ?string, type: string}>
     */
    public function search(string $companyId, string $query, int $limit = self::MAX_LIMIT): array
    {
        $query = trim($query);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        if (mb_strlen($query) < 2) {
            return [];
        }

        return ctype_digit($query)
            ? $this->searchByInn($companyId, $query, $limit)
            : $this->searchByName($companyId, $query, $limit);
    }

    /**
     * @return list<array{id: string, name: string, inn: ?string, kpp: ?string, type: string}>
     */
    private function searchByInn(string $companyId, string $query, int $limit): array
    {
        $sql = <<<'SQL'
            SELECT id, name, inn, kpp, type
            FROM "counterparty"
            WHERE company_id = :companyId
              AND is_archived = false
              AND inn LIKE :prefix
            ORDER BY (inn = :query) DESC, name ASC
            LIMIT :limit
            SQL;

        /** @var list<array{id: string, name: string, inn: ?string, kpp: ?string, type: string}> $rows */
        $rows = $this->connection->executeQuery($sql, [
            'companyId' => $companyId,
            'prefix' => $query.'%',
            'query' => $query,
            'limit' => $limit,
        ])->fetchAllAssociative();

        return $rows;
    }

    /**
     * @return list<array{id: string, name: string, inn: ?string, kpp: ?string, type: string}>
     */
    private function searchByName(string $companyId, string $query, int $limit): array
    {
        $core = $this->normalizer->normalize($query)->core;

        // Порог задан явным условием, а не оператором `%`: тот зависит от сессионной
        // настройки pg_trgm.similarity_threshold и вёл бы себя по-разному в тестах и проде.
        $sql = <<<'SQL'
            SELECT id, name, inn, kpp, type,
                   CASE
                       WHEN name_core = :core THEN 3.0
                       WHEN name_core LIKE :prefix THEN 2.0
                       ELSE similarity(name_core, :core)
                   END AS rank
            FROM "counterparty"
            WHERE company_id = :companyId
              AND is_archived = false
              AND name_core IS NOT NULL
              AND (name_core LIKE :prefix OR similarity(name_core, :core) > :threshold)
            ORDER BY rank DESC, name ASC
            LIMIT :limit
            SQL;

        $rows = $this->connection->executeQuery($sql, [
            'companyId' => $companyId,
            'core' => $core,
            'prefix' => $core.'%',
            'threshold' => self::SIMILARITY_THRESHOLD,
            'limit' => $limit,
        ])->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'inn' => null !== $row['inn'] ? (string) $row['inn'] : null,
                'kpp' => null !== $row['kpp'] ? (string) $row['kpp'] : null,
                'type' => (string) $row['type'],
            ],
            $rows,
        );
    }
}
