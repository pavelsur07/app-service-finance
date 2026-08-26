<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Company\Security\SystemCompanyRoles;
use App\Ingestion\Domain\SystemCounterparties;
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

    public function testResetRestoresMigrationSeededReferenceData(): void
    {
        (new DbReset())->reset($this->em);

        self::assertSame(
            \count(SystemCompanyRoles::definitions()),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM company_role WHERE company_id IS NULL'),
        );
        self::assertSame(
            \count(SystemCounterparties::definitions()),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM system_counterparties'),
        );
    }

    /**
     * Страховка от гниения списка в DbReset::restoreReferenceData().
     *
     * Ищет в миграциях статические seed-вставки (INSERT ... VALUES, в отличие от
     * backfill-вставок вида INSERT ... SELECT, которые на пустой тестовой БД
     * ничего не создают). Если появилась новая такая таблица, а восстановление
     * для неё не добавили, тест падает здесь — а не случайным падением чужого
     * теста в зависимости от порядка выполнения.
     */
    public function testEveryMigrationSeededTableIsRestored(): void
    {
        $restored = ['company_role', 'system_counterparties'];

        $files = glob(\dirname(__DIR__, 3).'/migrations/*.php');
        self::assertNotEmpty($files, 'Миграции не найдены — проверь путь.');

        $seeded = [];
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            self::assertIsString($sql);

            if (preg_match_all('/INSERT\s+INTO\s+"?([a-z_]+)"?\s*\([^)]*\)\s*VALUES/i', $sql, $matches)) {
                foreach ($matches[1] as $table) {
                    $seeded[$table] = true;
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_diff(array_keys($seeded), $restored)),
            'Миграции засевают таблицу, которую DbReset не восстанавливает после TRUNCATE.',
        );
    }
}
