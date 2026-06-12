<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use App\Tests\Support\Db\DbReset;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;

#[SkipDatabaseRollback]
abstract class PostgresResetTestCase extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetDb();
    }

    protected function resetDb(): void
    {
        (new DbReset())->reset($this->em);
    }
}
