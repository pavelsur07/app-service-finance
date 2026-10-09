<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Migration;

use App\Finance\Entity\PLCategory;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261009100000;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 4).'/migrations/Version20261009100000.php';

final class PLCategoryLevelMigrationTest extends IntegrationTestCase
{
    /** Один из 11 id, снятых на проде: уровень 3 → 2. */
    private const PROD_ROW_ID = '019fd25e-7310-708f-98c3-defcddae346c';

    public function testRecalculatesStaleLevelsAndRollsBackOnlySnapshottedRows(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1009)->withOwner($owner)->build();
        $other = CompanyBuilder::aCompany()->withIndex(1010)->withOwner($owner)->build();

        $group = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Общепроизводственные')->build();
        $rent = PLCategoryBuilder::aPLCategory()->forCompany($company)->withId(self::PROD_ROW_ID)->withName('Аренда')->withParent($group)->build();
        $detail = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Аренда склада')->withParent($rent)->build();
        $tax = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Налоги на ФОТ')->withParent($group)->build();
        $otherRoot = PLCategoryBuilder::aPLCategory()->forCompany($other)->withName('Доходы')->build();
        $otherLeaf = PLCategoryBuilder::aPLCategory()->forCompany($other)->withName('Продажи')->withParent($otherRoot)->build();

        foreach ([$owner, $company, $other, $group, $rent, $detail, $tax, $otherRoot, $otherLeaf] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        // Состояние прода: группу вынесли в корень, потомки остались глубже.
        $this->setLevel($rent, 3);
        $this->setLevel($detail, 4);

        $this->applyMigration(static fn (Version20261009100000 $m) => $m->up(new Schema()));

        self::assertSame(1, $this->levelOf($group));
        self::assertSame(2, $this->levelOf($rent));
        self::assertSame(3, $this->levelOf($detail));
        self::assertSame(2, $this->levelOf($tax));
        self::assertSame(1, $this->levelOf($otherRoot));
        self::assertSame(2, $this->levelOf($otherLeaf));

        $this->applyMigration(static fn (Version20261009100000 $m) => $m->down(new Schema()));

        self::assertSame(3, $this->levelOf($rent));
        // Строки вне снимка прода откат не трогает.
        self::assertSame(3, $this->levelOf($detail));
        self::assertSame(2, $this->levelOf($tax));
    }

    private function applyMigration(callable $step): void
    {
        $migration = new Version20261009100000($this->connection, new NullLogger());
        $step($migration);
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function setLevel(PLCategory $category, int $level): void
    {
        $this->connection->executeStatement('UPDATE pl_categories SET level = :level WHERE id = :id', ['level' => $level, 'id' => $category->getId()]);
    }

    private function levelOf(PLCategory $category): int
    {
        return (int) $this->connection->fetchOne('SELECT level FROM pl_categories WHERE id = :id', ['id' => $category->getId()]);
    }
}
