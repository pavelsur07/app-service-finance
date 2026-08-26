<?php

declare(strict_types=1);

namespace App\Tests\Support\Db;

use App\Company\Security\SystemCompanyRoles;
use App\Ingestion\Domain\SystemCounterparties;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Хелпер для тестовой БД (builders-first);
 * работа с БД остаётся явной и не скрывается.
 */
final class DbReset
{
    public function reset(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $tableNames = array_values(array_filter(
            $schemaManager->listTableNames(),
            // Служебная история должна переживать reset, иначе следующий прогон повторит все миграции.
            static function (string $tableName): bool {
                $parts = explode('.', $tableName);

                return 'doctrine_migration_versions' !== trim($parts[array_key_last($parts)], '"');
            },
        ));

        if ([] === $tableNames) {
            return;
        }

        // listTableNames() возвращает зарезервированные имена уже в кавычках ("user") —
        // снимаем их перед повторным квотированием, иначе получится ""user"".
        $quotedTables = array_map(
            static fn (string $tableName): string => implode('.', array_map(
                static fn (string $part): string => $connection->quoteIdentifier(trim($part, '"')),
                explode('.', $tableName),
            )),
            $tableNames,
        );
        $tableList = implode(', ', $quotedTables);

        $connection->executeStatement("SET session_replication_role = 'replica'");

        try {
            $connection->executeStatement(sprintf(
                'TRUNCATE %s RESTART IDENTITY CASCADE',
                $tableList
            ));
            $this->restoreReferenceData($connection);
        } finally {
            $connection->executeStatement("SET session_replication_role = 'origin'");
        }
    }

    /**
     * Восстанавливает справочные данные, засеянные миграциями.
     *
     * TRUNCATE сносит их вместе с тестовыми, а повторно миграции не идут:
     * doctrine_migration_versions переживает reset. Без восстановления любой
     * тест, опирающийся на системные роли или контрагентов, падает в
     * зависимости от того, запускался ли до него PostgresResetTestCase, —
     * то есть по порядку выполнения, а не по своему коду.
     *
     * Покрытие проверяется тестом DbResetTest: он сканирует миграции на
     * статические INSERT ... VALUES и падает, если появилась таблица,
     * которую здесь не восстанавливают.
     */
    private function restoreReferenceData(Connection $connection): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        foreach (SystemCompanyRoles::definitions() as $id => $definition) {
            $connection->executeStatement(
                'INSERT INTO company_role (id, company_id, name, permissions, created_at) VALUES (?, NULL, ?, ?, ?)',
                [
                    $id,
                    $definition['name'],
                    json_encode($definition['permissions'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                    $now,
                ],
            );
        }

        foreach (SystemCounterparties::definitions() as $id => $definition) {
            $connection->executeStatement(
                'INSERT INTO system_counterparties (id, source, name, inn, created_at) VALUES (?, ?, ?, NULL, ?)',
                [$id, $definition['source']->value, $definition['name'], $now],
            );
        }
    }
}
