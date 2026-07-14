<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use App\Tests\Support\Db\DbReset;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;

#[SkipDatabaseRollback]
abstract class PostgresResetTestCase extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // DAMA rollback отключён (#[SkipDatabaseRollback]), поэтому данные теста
        // остаются закоммиченными — чистим сами, иначе следующий (rollback-based)
        // тест унаследует их через общее соединение.
        if (isset($this->em)) {
            $this->resetDb();
        }

        parent::tearDown();
    }

    protected function resetDb(): void
    {
        (new DbReset())->reset($this->em);
    }
}
