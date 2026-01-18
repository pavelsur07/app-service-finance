<?php

declare(strict_types=1);

namespace App\Tests\Support\Db;

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
        $tableNames = $schemaManager->listTableNames();

        if ($tableNames === []) {
            return;
        }

        $quotedTables = array_map([$connection, 'quoteIdentifier'], $tableNames);
        $tableList = implode(', ', $quotedTables);

        $connection->executeStatement("SET session_replication_role = 'replica'");

        try {
            $connection->executeStatement(sprintf(
                'TRUNCATE %s RESTART IDENTITY CASCADE',
                $tableList
            ));
        } finally {
            $connection->executeStatement("SET session_replication_role = 'origin'");
        }
    }
}
