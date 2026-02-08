<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fund;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Cash\Entity\Accounts\MoneyFundMovement;
use App\Cash\Service\Accounts\FundBalanceService;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class FundBalanceServiceTest extends IntegrationTestCase
{
    public function testBalancesAndTotalsCalculatedPerFund(): void
    {
        // Важно: IntegrationTestCase уже bootKernel + truncateAllMappedTables()

        $companyOwner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('fund-owner@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($companyOwner)
            ->withName('Test Company')
            ->build();

        $otherOwner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('other-owner@example.test')
            ->build();

        $otherCompany = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($otherOwner)
            ->withName('Other Company')
            ->build();

        $this->em->persist($companyOwner);
        $this->em->persist($company);
        $this->em->persist($otherOwner);
        $this->em->persist($otherCompany);

        // Funds (company A)
        $fundOne = new MoneyFund(Uuid::uuid4()->toString(), $company, 'Налоги', 'RUB');
        $fundTwo = new MoneyFund(Uuid::uuid4()->toString(), $company, 'Зарплата', 'RUB');

        // Fund (company B) – должен НЕ влиять
        $otherFund = new MoneyFund(Uuid::uuid4()->toString(), $otherCompany, 'Чужой фонд', 'RUB');

        $this->em->persist($fundOne);
        $this->em->persist($fundTwo);
        $this->em->persist($otherFund);
        $this->em->flush();

        // Movements (company A)
        $m1 = new MoneyFundMovement(
            Uuid::uuid4()->toString(),
            $company,
            $fundOne,
            new \DateTimeImmutable('2024-01-01 00:00:00+00:00'),
            1000
        );

        $m2 = new MoneyFundMovement(
            Uuid::uuid4()->toString(),
            $company,
            $fundOne,
            new \DateTimeImmutable('2024-01-02 00:00:00+00:00'),
            -300
        );

        $m3 = new MoneyFundMovement(
            Uuid::uuid4()->toString(),
            $company,
            $fundTwo,
            new \DateTimeImmutable('2024-01-03 00:00:00+00:00'),
            500
        );

        // Movement (company B) – должен НЕ влиять
        $mOther = new MoneyFundMovement(
            Uuid::uuid4()->toString(),
            $otherCompany,
            $otherFund,
            new \DateTimeImmutable('2024-01-04 00:00:00+00:00'),
            700
        );

        $this->em->persist($m1);
        $this->em->persist($m2);
        $this->em->persist($m3);
        $this->em->persist($mOther);
        $this->em->flush();
        $this->em->clear();

        /** @var FundBalanceService $service */
        $service = self::getContainer()->get(FundBalanceService::class);

        $balances = $service->getFundBalances($company->getId());

        $map = [];
        foreach ($balances as $row) {
            $map[$row['fundId']] = $row;
        }

        self::assertSame(700, $map[$fundOne->getId()]['balanceMinor']);
        self::assertSame(500, $map[$fundTwo->getId()]['balanceMinor']);

        $totals = $service->getTotals($company->getId());
        self::assertSame(1200, $totals['RUB']);
    }
}
