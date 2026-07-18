<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Facts;

use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Finance\Enum\PLFlow;
use App\Finance\Facts\PLDailyTotalFactsProvider;
use App\Finance\Report\PlReportPeriod;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class PLDailyTotalFactsProviderTest extends IntegrationTestCase
{
    public function testValueCanBeFilteredByProjectAndResponsibilityCenter(): void
    {
        $owner = UserBuilder::aUser()
            ->withEmail('pl-facts-cfo@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($owner)
            ->withName('P&L facts CFO')
            ->build();

        $projectA = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Project A');
        $projectB = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Project B');
        $centerA = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_A', 'CFO A');
        $centerB = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_B', 'CFO B');
        $category = (new PLCategory(Uuid::uuid4()->toString(), $company))
            ->setName('Revenue')
            ->setCode('REV_CFO')
            ->setFlow(PLFlow::INCOME);

        foreach ([$owner, $company, $projectA, $projectB, $centerA, $centerB, $category] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $projectA, $centerA));
        $this->em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $projectA, $centerB));
        $this->em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $projectB, $centerA));

        $this->persistTotal($company, $projectA, $category, '100.00', $centerA->getId());
        $this->persistTotal($company, $projectA, $category, '40.00', $centerB->getId());
        $this->persistTotal($company, $projectA, $category, '5.00', null);
        $this->persistTotal($company, $projectB, $category, '7.00', $centerA->getId());
        $this->em->flush();

        /** @var PLDailyTotalFactsProvider $facts */
        $facts = self::getContainer()->get(PLDailyTotalFactsProvider::class);
        $period = PlReportPeriod::forRange(
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-31'),
        );

        self::assertSame(145.0, $facts->value($company, $period, 'REV_CFO', $projectA));
        self::assertSame(100.0, $facts->value($company, $period, 'REV_CFO', $projectA, $centerA->getId()));
        self::assertSame(40.0, $facts->value($company, $period, 'REV_CFO', $projectA, $centerB->getId()));
        self::assertSame(107.0, $facts->value($company, $period, 'REV_CFO', null, $centerA->getId()));
    }

    private function persistTotal(
        Company $company,
        ProjectDirection $project,
        PLCategory $category,
        string $income,
        ?string $responsibilityCenterId,
    ): void {
        $total = new PLDailyTotal(
            Uuid::uuid4()->toString(),
            $company,
            $project,
            new \DateTimeImmutable('2026-07-18'),
            $category,
        );
        $total
            ->setAmountIncome($income)
            ->setAmountExpense('0.00')
            ->setResponsibilityCenterId($responsibilityCenterId);

        $this->em->persist($total);
    }
}
