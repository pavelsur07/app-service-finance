<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Finance\Repository\PLMonthlySnapshotRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class PLRebuildAuditRepositoryTest extends IntegrationTestCase
{
    public function testDailyDeleteByAllShopsDeletesOnlyCompanyMonth(): void
    {
        [$companyA, $companyB, $projectA, $projectB] = $this->createCompaniesWithProjects();

        /** @var PLDailyTotalRepository $repository */
        $repository = self::getContainer()->get(PLDailyTotalRepository::class);

        $repository->upsert($companyA->getId(), null, new \DateTimeImmutable('2026-02-10'), $projectA->getId(), '10.00', '0.00', false);
        $repository->upsert($companyA->getId(), null, new \DateTimeImmutable('2026-03-01'), $projectA->getId(), '20.00', '0.00', false);
        $repository->upsert($companyB->getId(), null, new \DateTimeImmutable('2026-02-10'), $projectB->getId(), '30.00', '0.00', false);

        self::assertSame(1, $repository->deleteByCompanyShopAndMonth($companyA->getId(), '', 2026, 2));

        self::assertSame(0, $this->countRows('pl_daily_totals', $companyA->getId(), 'date', '2026-02-10'));
        self::assertSame(1, $this->countRows('pl_daily_totals', $companyA->getId(), 'date', '2026-03-01'));
        self::assertSame(1, $this->countRows('pl_daily_totals', $companyB->getId(), 'date', '2026-02-10'));
    }

    public function testMonthlyDeleteByAllShopsDeletesOnlyCompanyPeriod(): void
    {
        [$companyA, $companyB] = $this->createCompaniesWithProjects();

        /** @var PLMonthlySnapshotRepository $repository */
        $repository = self::getContainer()->get(PLMonthlySnapshotRepository::class);

        $repository->upsert($companyA->getId(), null, '2026-02', '10.00', '0.00');
        $repository->upsert($companyA->getId(), null, '2026-03', '20.00', '0.00');
        $repository->upsert($companyB->getId(), null, '2026-02', '30.00', '0.00');

        self::assertSame(1, $repository->deleteByCompanyShopAndMonth($companyA->getId(), '', 2026, 2));

        self::assertSame(0, $this->countRows('pl_monthly_snapshots', $companyA->getId(), 'period', '2026-02'));
        self::assertSame(1, $this->countRows('pl_monthly_snapshots', $companyA->getId(), 'period', '2026-03'));
        self::assertSame(1, $this->countRows('pl_monthly_snapshots', $companyB->getId(), 'period', '2026-02'));
    }

    public function testMonthlyShopScopedDeleteIsRejectedUntilPnlStoresShopRef(): void
    {
        [$companyA] = $this->createCompaniesWithProjects();

        /** @var PLMonthlySnapshotRepository $monthlyRepository */
        $monthlyRepository = self::getContainer()->get(PLMonthlySnapshotRepository::class);

        $this->expectException(\LogicException::class);
        $monthlyRepository->deleteByCompanyShopAndMonth($companyA->getId(), 'ozon:shop-1', 2026, 2);
    }

    public function testDailyShopScopedDeleteIsRejectedUntilPnlStoresShopRef(): void
    {
        [$companyA] = $this->createCompaniesWithProjects();

        /** @var PLDailyTotalRepository $dailyRepository */
        $dailyRepository = self::getContainer()->get(PLDailyTotalRepository::class);

        $this->expectException(\LogicException::class);
        $dailyRepository->deleteByCompanyShopAndMonth($companyA->getId(), 'ozon:shop-1', 2026, 2);
    }

    /**
     * @return array{Company, Company, ProjectDirection, ProjectDirection}
     */
    private function createCompaniesWithProjects(): array
    {
        $userA = new User(Uuid::uuid4()->toString());
        $userA->setEmail('pnl-a@example.com');
        $userA->setPassword('password');
        $companyA = new Company(Uuid::uuid4()->toString(), $userA);
        $companyA->setName('P&L A');
        $projectA = new ProjectDirection(Uuid::uuid4()->toString(), $companyA, 'Main A');

        $userB = new User(Uuid::uuid4()->toString());
        $userB->setEmail('pnl-b@example.com');
        $userB->setPassword('password');
        $companyB = new Company(Uuid::uuid4()->toString(), $userB);
        $companyB->setName('P&L B');
        $projectB = new ProjectDirection(Uuid::uuid4()->toString(), $companyB, 'Main B');

        foreach ([$userA, $userB, $companyA, $companyB, $projectA, $projectB] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return [$companyA, $companyB, $projectA, $projectB];
    }

    private function countRows(string $table, string $companyId, string $column, string $value): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE company_id = :company_id AND %s = :value', $table, $column),
            ['company_id' => $companyId, 'value' => $value],
        );
    }
}
