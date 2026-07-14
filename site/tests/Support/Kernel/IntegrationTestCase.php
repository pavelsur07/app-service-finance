<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->resetDb();
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

    /**
     * По умолчанию ничего не делает: изоляция между тестами уже обеспечена
     * транзакцией DAMA\DoctrineTestBundle (rollback после каждого теста), поэтому
     * TRUNCATE всех таблиц здесь был избыточен — выполнялся внутри той же
     * транзакции и терялся при откате. Переопределяется в PostgresResetTestCase
     * для тестов, где DAMA rollback осознанно отключён (#[SkipDatabaseRollback]).
     */
    protected function resetDb(): void
    {
    }
}
