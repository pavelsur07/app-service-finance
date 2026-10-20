<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Persistence;

use App\Company\DTO\SpamAccountCandidate;
use App\Company\Infrastructure\Query\SpamAccountCandidatesQuery;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Удаляет порцию спам-аккаунтов: автосид кабинетов → кабинеты → пользователей.
 * Транзакцию ведёт вызывающая команда; кандидаты — из SpamAccountCandidatesQuery
 * того же прогона.
 */
final readonly class SpamAccountPurger
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<SpamAccountCandidate> $candidates
     *
     * @return array<string, int> таблица → удалено строк
     */
    public function purge(array $candidates): array
    {
        if ([] === $candidates) {
            return [];
        }

        $userIds = array_map(static fn (SpamAccountCandidate $c): string => $c->userId, $candidates);
        $companyIds = array_merge(...array_map(static fn (SpamAccountCandidate $c): array => $c->companyIds, $candidates));
        $types = ['ids' => ArrayParameterType::STRING];

        $deleted = [];
        foreach (SpamAccountCandidatesQuery::AUTO_SEED_TABLES as $table) {
            $deleted[$table] = (int) $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE company_id IN (:ids)', $this->connection->quoteSingleIdentifier($table)),
                ['ids' => $companyIds],
                $types,
            );
        }
        $deleted['companies'] = (int) $this->connection->executeStatement(
            'DELETE FROM companies WHERE id IN (:ids)',
            ['ids' => $companyIds],
            $types,
        );
        $deleted['user'] = (int) $this->connection->executeStatement(
            'DELETE FROM "user" WHERE id IN (:ids)',
            ['ids' => $userIds],
            $types,
        );

        return $deleted;
    }
}
