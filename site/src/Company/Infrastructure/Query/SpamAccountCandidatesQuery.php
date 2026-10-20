<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Query;

use App\Company\DTO\SpamAccountCandidate;
use App\Company\DTO\SpamAccountScan;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Поиск спам-аккаунтов: пользователь из окна регистрации, не админ, без каких-либо
 * ссылок на себя, кроме собственных кабинетов, и кабинеты без данных, кроме автосида.
 *
 * Таблицы со ссылками берутся из схемы БД во время запуска (FK из pg_constraint и
 * колонки `user_id` / `company_id`), поэтому новая таблица с данными исключает
 * аккаунт автоматически, а не пропускается.
 */
final readonly class SpamAccountCandidatesQuery
{
    /**
     * Строки, которые создаются автоматически вместе с кабинетом; в порядке удаления.
     */
    public const AUTO_SEED_TABLES = [
        'financial_responsibility_center_projects',
        'financial_responsibility_centers',
        'project_directions',
    ];

    private const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];
    private const CHUNK = 1000;

    public function __construct(private Connection $connection)
    {
    }

    public function scan(\DateTimeImmutable $registeredFrom, \DateTimeImmutable $registeredTo): SpamAccountScan
    {
        $users = $this->connection->fetchAllAssociative(
            'SELECT u.id, u.roles FROM "user" u WHERE u.created_at >= :from AND u.created_at < :to ORDER BY u.created_at, u.id',
            ['from' => $registeredFrom->format('Y-m-d H:i:s'), 'to' => $registeredTo->format('Y-m-d H:i:s')],
        );

        /** @var array<string, string> $rejected userId → причина */
        $rejected = [];
        $userIds = [];
        foreach ($users as $row) {
            $userId = (string) $row['id'];
            $userIds[] = $userId;
            $roles = json_decode((string) $row['roles'], true);
            if (\is_array($roles) && [] !== array_intersect(self::ADMIN_ROLES, $roles)) {
                $rejected[$userId] = 'admin';
            }
        }

        /** @var array<string, non-empty-list<string>> $companiesByUser */
        $companiesByUser = [];
        foreach ($this->fetchByIds('SELECT c.user_id AS owner, c.id FROM companies c WHERE c.user_id IN (:ids)', $userIds) as $row) {
            $companiesByUser[(string) $row['owner']][] = (string) $row['id'];
        }
        foreach ($userIds as $userId) {
            if (!isset($companiesByUser[$userId])) {
                $rejected[$userId] ??= 'no_company';
            }
        }

        foreach ($this->referencingColumns('user', 'user_id') as [$table, $column]) {
            if ('companies' === $table && 'user_id' === $column) {
                continue;
            }
            foreach ($this->referencedIds($table, $column, $userIds) as $userId) {
                $rejected[$userId] ??= $table.'.'.$column;
            }
        }

        $ownerByCompany = [];
        foreach ($companiesByUser as $userId => $companyIds) {
            foreach ($companyIds as $companyId) {
                $ownerByCompany[$companyId] = $userId;
            }
        }
        foreach ($this->referencingColumns('companies', 'company_id') as [$table, $column]) {
            if ('companies' === $table || \in_array($table, self::AUTO_SEED_TABLES, true)) {
                continue;
            }
            foreach ($this->referencedIds($table, $column, array_keys($ownerByCompany)) as $companyId) {
                $rejected[$ownerByCompany[$companyId]] ??= $table.'.'.$column;
            }
        }

        $candidates = [];
        foreach ($userIds as $userId) {
            if (!isset($rejected[$userId]) && isset($companiesByUser[$userId])) {
                $candidates[] = new SpamAccountCandidate($userId, $companiesByUser[$userId]);
            }
        }

        $rejectedByReason = array_count_values($rejected);
        ksort($rejectedByReason);

        return new SpamAccountScan(\count($userIds), $candidates, $rejectedByReason);
    }

    /**
     * Колонки, ссылающиеся на таблицу: внешние ключи плюс колонки с конвенциональным
     * именем без FK (только строковые и uuid: `user_id` бывает и числовым id Telegram).
     *
     * @return list<array{string, string}> [таблица, колонка]
     */
    private function referencingColumns(string $referencedTable, string $conventionalColumn): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT cl.relname AS tbl, a.attname AS col
             FROM pg_constraint con
             JOIN pg_class cl ON cl.oid = con.conrelid
             JOIN pg_namespace n ON n.oid = cl.relnamespace
             JOIN pg_class ref ON ref.oid = con.confrelid
             JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = con.conkey[1]
             WHERE con.contype = 'f' AND n.nspname = current_schema() AND ref.relname = :ref
             UNION
             SELECT c.table_name AS tbl, c.column_name AS col
             FROM information_schema.columns c
             JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
             WHERE c.table_schema = current_schema() AND t.table_type = 'BASE TABLE' AND c.column_name = :col
               AND c.data_type IN ('uuid', 'character varying', 'text')
             ORDER BY 1, 2",
            ['ref' => $referencedTable, 'col' => $conventionalColumn],
        );

        return array_map(static fn (array $row): array => [(string) $row['tbl'], (string) $row['col']], $rows);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> те из $ids, на которые есть ссылка в таблице
     */
    private function referencedIds(string $table, string $column, array $ids): array
    {
        $sql = \sprintf(
            'SELECT DISTINCT %2$s::text AS id FROM %1$s WHERE %2$s IN (:ids)',
            $this->connection->quoteSingleIdentifier($table),
            $this->connection->quoteSingleIdentifier($column),
        );

        return array_map(static fn (array $row): string => (string) $row['id'], $this->fetchByIds($sql, $ids));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function fetchByIds(string $sql, array $ids): array
    {
        $rows = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            array_push($rows, ...$this->connection->fetchAllAssociative($sql, ['ids' => $chunk], ['ids' => ArrayParameterType::STRING]));
        }

        return $rows;
    }
}
