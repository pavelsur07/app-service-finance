<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Entity\PLCategory;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class PLDailyTotalRepositoryTest extends IntegrationTestCase
{
    public function testProjectCenterConflictTargetsAndCategoryDeletionKeepNullableKeySemantics(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('pnl-responsibility-center@example.test');
        $user->setPassword('password');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('P&L responsibility center');
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Main');
        $centerA = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_A', 'CFO A');
        $centerB = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_B', 'CFO B');
        $category = new PLCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Revenue');

        foreach ([$user, $company, $project, $centerA, $centerB, $category] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $companyId = $company->getId();
        $categoryId = $category->getId();
        $projectId = $project->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($categoryId);
        self::assertNotNull($projectId);

        /** @var PLDailyTotalRepository $repository */
        $repository = self::getContainer()->get(PLDailyTotalRepository::class);
        $date = new \DateTimeImmutable('2026-07-17');

        $repository->upsert($companyId, null, $date, $projectId, '10.00', '2.00', false, responsibilityCenterId: $centerA->getId());
        $repository->upsert($companyId, null, $date, $projectId, '1.00', '1.00', false, responsibilityCenterId: $centerA->getId());
        $repository->upsert($companyId, $categoryId, $date, $projectId, '20.00', '4.00', false, responsibilityCenterId: $centerA->getId());
        $repository->upsert($companyId, $categoryId, $date, $projectId, '2.00', '1.00', false, responsibilityCenterId: $centerA->getId());
        $repository->upsert($companyId, $categoryId, $date, $projectId, '30.00', '6.00', false, responsibilityCenterId: $centerB->getId());
        $repository->upsert($companyId, $categoryId, $date, $projectId, '3.00', '1.00', false);
        $repository->upsert($companyId, $categoryId, $date, $projectId, '4.00', '2.00', false);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT pl_category_id, amount_income, amount_expense, responsibility_center_id
                FROM pl_daily_totals
                WHERE company_id = :company_id
                ORDER BY pl_category_id NULLS FIRST, responsibility_center_id NULLS FIRST, amount_income
                SQL,
            ['company_id' => $companyId],
        );

        self::assertCount(4, $rows);
        self::assertNull($rows[0]['pl_category_id']);
        self::assertSame('11.00', $rows[0]['amount_income']);
        self::assertSame('3.00', $rows[0]['amount_expense']);
        self::assertSame($centerA->getId(), $rows[0]['responsibility_center_id']);
        self::assertSame($categoryId, $rows[1]['pl_category_id']);
        self::assertSame('7.00', $rows[1]['amount_income']);
        self::assertSame('3.00', $rows[1]['amount_expense']);
        self::assertNull($rows[1]['responsibility_center_id']);
        self::assertSame($categoryId, $rows[2]['pl_category_id']);
        self::assertSame('22.00', $rows[2]['amount_income']);
        self::assertSame('5.00', $rows[2]['amount_expense']);
        self::assertSame($centerA->getId(), $rows[2]['responsibility_center_id']);
        self::assertSame($categoryId, $rows[3]['pl_category_id']);
        self::assertSame('30.00', $rows[3]['amount_income']);
        self::assertSame('6.00', $rows[3]['amount_expense']);
        self::assertSame($centerB->getId(), $rows[3]['responsibility_center_id']);

        $repository->moveCategoryRowsToUncategorized($companyId, $categoryId);
        $this->em->remove($category);
        $this->em->flush();

        $uncategorizedRows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT amount_income, amount_expense, responsibility_center_id
                FROM pl_daily_totals
                WHERE company_id = :company_id
                  AND pl_category_id IS NULL
                ORDER BY responsibility_center_id NULLS FIRST
                SQL,
            ['company_id' => $companyId],
        );

        self::assertCount(3, $uncategorizedRows);
        self::assertSame('7.00', $uncategorizedRows[0]['amount_income']);
        self::assertSame('3.00', $uncategorizedRows[0]['amount_expense']);
        self::assertNull($uncategorizedRows[0]['responsibility_center_id']);
        self::assertSame('33.00', $uncategorizedRows[1]['amount_income']);
        self::assertSame('8.00', $uncategorizedRows[1]['amount_expense']);
        self::assertSame($centerA->getId(), $uncategorizedRows[1]['responsibility_center_id']);
        self::assertSame('30.00', $uncategorizedRows[2]['amount_income']);
        self::assertSame('6.00', $uncategorizedRows[2]['amount_expense']);
        self::assertSame($centerB->getId(), $uncategorizedRows[2]['responsibility_center_id']);
    }

    public function testUpsertFallsBackToLegacyConflictTargetBeforeProjectCenterMigration(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('pnl-legacy-daily-key@example.test');
        $user->setPassword('password');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('P&L legacy daily key');
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Main');
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_LEGACY', 'Legacy CFO');
        $category = new PLCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Legacy revenue');

        foreach ([$user, $company, $project, $center, $category] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $this->connection->executeStatement('DROP INDEX IF EXISTS uniq_pl_daily_company_cat_date_project_center');
        $this->connection->executeStatement('DROP INDEX IF EXISTS uniq_pl_daily_uncat_date_project_center');
        $this->connection->executeStatement(
            'ALTER TABLE pl_daily_totals ADD CONSTRAINT uniq_pl_daily_company_cat_date UNIQUE (company_id, pl_category_id, date, project_direction_id)',
        );

        /** @var PLDailyTotalRepository $repository */
        $repository = self::getContainer()->get(PLDailyTotalRepository::class);
        $this->resetProjectCenterUniquenessCache($repository);

        $companyId = (string) $company->getId();
        $categoryId = (string) $category->getId();
        $projectId = (string) $project->getId();
        $date = new \DateTimeImmutable('2026-07-18');

        $repository->upsert($companyId, $categoryId, $date, $projectId, '10.00', '1.00', false, responsibilityCenterId: $center->getId());
        self::assertFalse($this->getProjectCenterUniquenessCache($repository));

        $repository->upsert($companyId, $categoryId, $date, $projectId, '2.00', '3.00', false, responsibilityCenterId: $center->getId());
        $repository->upsert($companyId, null, $date, $projectId, '5.00', '6.00', false, responsibilityCenterId: $center->getId());
        $repository->moveCategoryRowsToUncategorized($companyId, $categoryId);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT pl_category_id, amount_income, amount_expense, responsibility_center_id
                FROM pl_daily_totals
                WHERE company_id = :company_id
                ORDER BY pl_category_id NULLS FIRST
                SQL,
            ['company_id' => $companyId],
        );

        self::assertCount(2, $rows);
        self::assertNull($rows[0]['pl_category_id']);
        self::assertSame('5.00', $rows[0]['amount_income']);
        self::assertSame('6.00', $rows[0]['amount_expense']);
        self::assertNull($rows[0]['responsibility_center_id']);
        self::assertSame($categoryId, $rows[1]['pl_category_id']);
        self::assertSame('12.00', $rows[1]['amount_income']);
        self::assertSame('4.00', $rows[1]['amount_expense']);
        self::assertNull($rows[1]['responsibility_center_id']);
    }

    private function resetProjectCenterUniquenessCache(PLDailyTotalRepository $repository): void
    {
        $property = new \ReflectionProperty($repository, 'projectCenterUniquenessEnabled');
        $property->setValue($repository, null);
    }

    private function getProjectCenterUniquenessCache(PLDailyTotalRepository $repository): ?bool
    {
        $property = new \ReflectionProperty($repository, 'projectCenterUniquenessEnabled');

        return $property->getValue($repository);
    }
}
