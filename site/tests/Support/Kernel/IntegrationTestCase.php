<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // Единая “чистка” перед каждым тестом: по реальным таблицам ORM (без хардкода).
        $this->truncateAllMappedTables();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
            $this->em->getConnection()->close();
        }

        parent::tearDown();
        self::ensureKernelShutdown();
    }

    protected function truncateAllMappedTables(): void
    {
        $conn = $this->em->getConnection();
        $platform = $conn->getDatabasePlatform();
        $schemaManager = $conn->createSchemaManager();

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();

        $tables = [];
        foreach ($metadata as $m) {
            $tableName = $m->getTableName();
            if (!is_string($tableName) || '' === $tableName) {
                continue;
            }
            // В маппинге зарезервированные имена обёрнуты в backticks (`user`) —
            // снимаем их, иначе имя не совпадёт со списком таблиц БД и таблица не очистится.
            $tables[] = trim($tableName, '`');
        }

        $tables = array_values(array_unique($tables));
        if ([] === $tables) {
            return;
        }

        // listTableNames() возвращает зарезервированные имена в кавычках ("user") —
        // нормализуем, иначе такие таблицы не пройдут фильтр и не будут очищены.
        $existingTables = array_map(
            static fn (string $t): string => strtolower(trim($t, '"')),
            $schemaManager->listTableNames(),
        );
        $existingLookup = array_fill_keys($existingTables, true);

        $tables = array_values(array_filter(
            $tables,
            static fn (string $table): bool => isset($existingLookup[strtolower($table)])
        ));

        if ([] === $tables) {
            return;
        }

        // Postgres-friendly: временно отключаем триггеры/проверки FK.
        $conn->executeStatement('SET session_replication_role = replica');

        $quoted = array_map(static fn (string $t) => $platform->quoteIdentifier($t), $tables);
        $sql = sprintf('TRUNCATE TABLE %s CASCADE', implode(', ', $quoted));
        $conn->executeStatement($sql);

        $conn->executeStatement('SET session_replication_role = origin');
    }
}
