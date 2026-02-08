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

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();

        $tables = [];
        foreach ($metadata as $m) {
            $tableName = $m->getTableName();
            if (!is_string($tableName) || '' === $tableName) {
                continue;
            }
            $tables[] = $tableName;
        }

        $tables = array_values(array_unique($tables));
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
