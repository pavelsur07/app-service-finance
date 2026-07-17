<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Entity\PLCategory;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class PLDailyTotalRepositoryTest extends IntegrationTestCase
{
    public function testCurrentConflictTargetAndCategoryDeletionKeepNullableKeySemantics(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('pnl-responsibility-center@example.test');
        $user->setPassword('password');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('P&L responsibility center');
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Main');
        $category = new PLCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Revenue');

        foreach ([$user, $company, $project, $category] as $entity) {
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

        $repository->upsert($companyId, null, $date, $projectId, '10.00', '2.00', false);
        $repository->upsert($companyId, $categoryId, $date, $projectId, '20.00', '4.00', false);
        $repository->upsert($companyId, $categoryId, $date, $projectId, '2.00', '1.00', false);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT pl_category_id, amount_income, amount_expense, responsibility_center_id
                FROM pl_daily_totals
                WHERE company_id = :company_id
                ORDER BY amount_income
                SQL,
            ['company_id' => $companyId],
        );

        self::assertCount(2, $rows);
        self::assertNull($rows[0]['pl_category_id']);
        self::assertSame('10.00', $rows[0]['amount_income']);
        self::assertSame('2.00', $rows[0]['amount_expense']);
        self::assertNull($rows[0]['responsibility_center_id']);
        self::assertSame($categoryId, $rows[1]['pl_category_id']);
        self::assertSame('22.00', $rows[1]['amount_income']);
        self::assertSame('5.00', $rows[1]['amount_expense']);
        self::assertNull($rows[1]['responsibility_center_id']);

        $this->em->remove($category);
        $this->em->flush();

        self::assertSame(
            2,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM pl_daily_totals WHERE company_id = :company_id AND pl_category_id IS NULL',
                ['company_id' => $companyId],
            ),
        );
    }
}
