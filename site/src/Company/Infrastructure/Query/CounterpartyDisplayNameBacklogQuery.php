<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Очередь пересчёта отображаемого названия (docs/plan/counterparty-display-name.md): строки без
 * авто-названия текущей версии правил; ручные — никогда. Скалярные строки, без гидрации.
 *
 * ВНИМАНИЕ: кросс-компанийный — системный пересчёт из CLI; из HTTP-контекста не вызывать.
 */
final class CounterpartyDisplayNameBacklogQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Порция по возрастанию id после $afterId (keyset: порции не съезжают, пока пересчёт пишет).
     *
     * @return list<array{id: string, company_id: string, name: string, display_name: ?string}>
     */
    public function fetch(int $rulesVersion, ?string $afterId, int $limit): array
    {
        $sql = <<<'SQL'
            SELECT id, company_id, name, display_name
            FROM "counterparty"
            WHERE (display_name_source IS NULL
                   OR (display_name_source = :auto AND (display_rules_version IS NULL OR display_rules_version <> :version)))
              AND (CAST(:afterId AS uuid) IS NULL OR id > CAST(:afterId AS uuid))
            ORDER BY id
            LIMIT :limit
            SQL;

        /** @var list<array{id: string, company_id: string, name: string, display_name: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            ['auto' => 'auto', 'version' => $rulesVersion, 'afterId' => $afterId, 'limit' => $limit],
            ['version' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return $rows;
    }
}
