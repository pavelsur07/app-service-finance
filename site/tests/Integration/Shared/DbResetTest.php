<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Db\DbReset;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class DbResetTest extends IntegrationTestCase
{
    public function testResetPreservesMigrationMetadata(): void
    {
        $versionsBeforeReset = $this->connection->fetchFirstColumn(
            'SELECT version FROM doctrine_migration_versions ORDER BY version',
        );

        self::assertNotEmpty($versionsBeforeReset);

        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        // DAMA откатит полный reset после проверки и восстановит исходные fixtures.
        (new DbReset())->reset($this->em);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM companies'));
        self::assertSame(
            $versionsBeforeReset,
            $this->connection->fetchFirstColumn(
                'SELECT version FROM doctrine_migration_versions ORDER BY version',
            ),
        );
    }
}
