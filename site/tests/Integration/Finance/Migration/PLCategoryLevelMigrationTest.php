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
    /** Из снимка прода: строка (уровень 3 → 2) и её родитель. */
    private const PROD_ROW_ID = '019fd25e-7310-708f-98c3-defcddae346c';
    private const PROD_PARENT_ID = '019fd25e-730f-7366-8798-a775aff09d0c';
    /** Из того же снимка, но к откату её перенесут к другому корню. */
    private const PROD_MOVED_ROW_ID = '019fd25e-7311-72f6-b3d7-b5108ac8c98b';

    public function testRecalculatesStaleLevelsAndRollsBackOnlySnapshottedRows(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1009)->withOwner($owner)->build();
        $other = CompanyBuilder::aCompany()->withIndex(1010)->withOwner($owner)->build();

        $group = PLCategoryBuilder::aPLCategory()->forCompany($company)->withId(self::PROD_PARENT_ID)->withName('Общепроизводственные')->build();
        $otherGroup = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Коммерческие')->build();
        $moved = PLCategoryBuilder::aPLCategory()->forCompany($company)->withId(self::PROD_MOVED_ROW_ID)->withName('Коммуналка')->withParent($group)->build();
        $rent = PLCategoryBuilder::aPLCategory()->forCompany($company)->withId(self::PROD_ROW_ID)->withName('Аренда')->withParent($group)->build();
        $detail = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Аренда склада')->withParent($rent)->build();
        $tax = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Налоги на ФОТ')->withParent($group)->build();
        $otherRoot = PLCategoryBuilder::aPLCategory()->forCompany($other)->withName('Доходы')->build();
        $otherLeaf = PLCategoryBuilder::aPLCategory()->forCompany($other)->withName('Продажи')->withParent($otherRoot)->build();

        foreach ([$owner, $company, $other, $group, $otherGroup, $moved, $rent, $detail, $tax, $otherRoot, $otherLeaf] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        // Состояние прода: группу вынесли в корень, потомки остались глубже.
        $this->setLevel($rent, 3);
        $this->setLevel($detail, 4);
        $this->setLevel($moved, 3);

        $this->applyMigration(static fn (Version20261009100000 $m) => $m->up(new Schema()));

        self::assertSame(1, $this->levelOf($group));
        self::assertSame(2, $this->levelOf($rent));
        self::assertSame(3, $this->levelOf($detail));
        self::assertSame(2, $this->levelOf($tax));
        self::assertSame(1, $this->levelOf($otherRoot));
        self::assertSame(2, $this->levelOf($otherLeaf));
        self::assertSame(2, $this->levelOf($moved));

        // После миграции статью перенесли к другому корню: level 2 верен и там.
        $this->connection->executeStatement('UPDATE pl_categories SET parent_id = :parent WHERE id = :id', ['parent' => $otherGroup->getId(), 'id' => $moved->getId()]);

        $this->applyMigration(static fn (Version20261009100000 $m) => $m->down(new Schema()));

        self::assertSame(3, $this->levelOf($rent));
        // Перенесённую строку откат не ломает, строки вне снимка не трогает.
        self::assertSame(2, $this->levelOf($moved));
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
