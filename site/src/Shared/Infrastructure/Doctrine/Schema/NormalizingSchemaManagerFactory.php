<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Schema\SchemaManagerFactory;

/** Подключён в `doctrine.dbal.schema_manager_factory`; см. {@see NormalizingPostgreSQLSchemaManager}. */
final readonly class NormalizingSchemaManagerFactory implements SchemaManagerFactory
{
    /** @return AbstractSchemaManager<AbstractPlatform> */
    public function createSchemaManager(Connection $connection): AbstractSchemaManager
    {
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            return new NormalizingPostgreSQLSchemaManager($connection, $platform);
        }

        return (new DefaultSchemaManagerFactory())->createSchemaManager($connection);
    }
}
