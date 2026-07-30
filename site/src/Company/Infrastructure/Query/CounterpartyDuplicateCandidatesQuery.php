<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Отчёт по кандидатам-дублям справочника. Только чтение: решение о слиянии
 * принимает человек, автоматических правок здесь нет.
 *
 * ВНИМАНИЕ: методы кросс-компанийные по умолчанию — это CLI-отчёт. Для вызова из
 * HTTP-контекста companyId обязателен (иначе IDOR).
 */
final class CounterpartyDuplicateCandidatesQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Пары с похожим нормализованным названием.
     *
     * ОПФ обязана совпадать: «ООО "Балтийский лизинг"» и «АО "Балтийский лизинг"» —
     * разные юрлица с разными ИНН, и склеивать их нельзя.
     *
     * @return list<array{company_id: string, left_id: string, left_name: string, left_inn: ?string, right_id: string, right_name: string, right_inn: ?string, legal_form_hint: ?string, similarity: float}>
     */
    public function findSimilarNamePairs(float $threshold = 0.6, ?string $companyId = null): array
    {
        // ponytail: self-join O(n²) — на текущем объёме (сотни строк) это миллисекунды;
        // при десятках тысяч строк понадобится GIN-индекс по name_core и постраничный обход.
        $sql = <<<'SQL'
            SELECT a.company_id AS company_id,
                   a.id AS left_id,
                   a.name AS left_name,
                   a.inn AS left_inn,
                   b.id AS right_id,
                   b.name AS right_name,
                   b.inn AS right_inn,
                   a.legal_form_hint AS legal_form_hint,
                   similarity(a.name_core, b.name_core) AS similarity
            FROM "counterparty" a
            JOIN "counterparty" b
              ON b.company_id = a.company_id
             AND b.id > a.id
             AND a.legal_form_hint IS NOT DISTINCT FROM b.legal_form_hint
            WHERE a.name_core IS NOT NULL
              AND b.name_core IS NOT NULL
              AND a.is_archived = false
              AND b.is_archived = false
              AND similarity(a.name_core, b.name_core) > :threshold
            SQL;

        $parameters = ['threshold' => $threshold];

        // Фильтр по компании добавляется условием, а не «:companyId IS NULL»:
        // PostgreSQL не может вывести тип параметра, используемого только в IS NULL.
        if (null !== $companyId) {
            $sql .= "\n              AND a.company_id = :companyId";
            $parameters['companyId'] = $companyId;
        }

        $sql .= "\n            ORDER BY similarity DESC, a.company_id";

        $rows = $this->connection->executeQuery($sql, $parameters)->fetchAllAssociative();

        // pdo_pgsql отдаёт real строкой — приводим здесь, чтобы контракт метода был честным.
        return array_map(
            static fn (array $row): array => ['similarity' => (float) $row['similarity']] + $row,
            $rows,
        );
    }

    /**
     * Группы с одинаковым ИНН внутри компании — самый надёжный признак дубля.
     *
     * Архивные строки здесь учитываются намеренно: архивный дубль всё равно занимает
     * ИНН и попадёт в будущий UNIQUE-индекс, в отличие от похожести названий, где
     * архив только шумит.
     *
     * @return list<array{company_id: string, inn: string, rows: int, names: string}>
     */
    public function findSameInnGroups(?string $companyId = null): array
    {
        $sql = <<<'SQL'
            SELECT company_id,
                   inn,
                   count(*) AS rows,
                   string_agg(name, ' | ' ORDER BY name) AS names
            FROM "counterparty"
            WHERE inn IS NOT NULL
            SQL;

        $parameters = [];

        if (null !== $companyId) {
            $sql .= "\n              AND company_id = :companyId";
            $parameters['companyId'] = $companyId;
        }

        $sql .= "\n            GROUP BY company_id, inn\n            HAVING count(*) > 1\n            ORDER BY count(*) DESC";

        $rows = $this->connection->executeQuery($sql, $parameters)->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => ['rows' => (int) $row['rows']] + $row,
            $rows,
        );
    }

    /**
     * ИНН, который не пройдёт валидацию при следующей правке карточки.
     *
     * @return list<array{id: string, company_id: string, name: string, inn: string}>
     */
    public function findInvalidInnRows(?string $companyId = null): array
    {
        $sql = <<<'SQL'
            SELECT id, company_id, name, inn
            FROM "counterparty"
            WHERE inn IS NOT NULL
              AND inn !~ '^[0-9]{10}([0-9]{2})?$'
            SQL;

        $parameters = [];

        if (null !== $companyId) {
            $sql .= "\n              AND company_id = :companyId";
            $parameters['companyId'] = $companyId;
        }

        $sql .= "\n            ORDER BY company_id, name";

        /** @var list<array{id: string, company_id: string, name: string, inn: string}> $rows */
        $rows = $this->connection->executeQuery($sql, $parameters)->fetchAllAssociative();

        return $rows;
    }
}
