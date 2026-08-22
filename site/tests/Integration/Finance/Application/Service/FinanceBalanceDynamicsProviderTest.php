<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Application\Service;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Company\Entity\Company;
use App\Finance\Application\Service\FinanceBalanceDynamicsProvider;
use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportParams;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class FinanceBalanceDynamicsProviderTest extends IntegrationTestCase
{
    public function testBuildAlignsBalancesAndActivityFlowsWithCashflowReportScope(): void
    {
        $today = new \DateTimeImmutable('2026-08-15');
        $user = UserBuilder::aUser()->withEmail('balance-dynamics@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $company->setMinimumBalance(Money::fromString('75.00', 'RUB'));
        $mainAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Main RUB account')
            ->build()
            ->setOpeningBalance('100.00');
        $recentAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Recent RUB account')
            ->build()
            ->setOpeningBalance('10.00')
            ->setOpeningBalanceDate(new \DateTimeImmutable('2026-08-10'));
        $inactiveAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Inactive RUB account')
            ->build()
            ->setOpeningBalance('999.00')
            ->setIsActive(false);
        $usdAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('USD account')
            ->withCurrency('USD')
            ->build()
            ->setOpeningBalance('500.00');

        $foreignUser = UserBuilder::aUser()->withIndex(2)->build();
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($foreignUser)->build();
        $foreignAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($foreignCompany)
            ->withName('Foreign account')
            ->build()
            ->setOpeningBalance('700.00');

        foreach ([$user, $company, $mainAccount, $recentAccount, $inactiveAccount, $usdAccount, $foreignUser, $foreignCompany, $foreignAccount] as $entity) {
            $this->em->persist($entity);
        }
        foreach ([
            ['2026-07-17', '80.00'],
            ['2026-08-14', '60.00'],
            ['2026-08-15', '90.00'],
        ] as [$date, $balance]) {
            $this->persistBalance($company, $mainAccount, $date, $balance, 'RUB');
        }
        $this->persistBalance($foreignCompany, $foreignAccount, '2026-08-15', '900.00', 'RUB');
        $this->em->flush();

        $categories = self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        $foreignCategories = self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($foreignCompany);
        $this->persistTransactionWithSplits(
            $company,
            $mainAccount,
            CashDirection::INFLOW,
            '100.25',
            $today,
            [
                [$categories[CashflowCategory::CODE_OPERATING], '60.10'],
                [$categories[CashflowCategory::CODE_FINANCING], '40.15'],
            ],
        );
        $this->persistTransaction($company, $mainAccount, $categories[CashflowCategory::CODE_OPERATING], CashDirection::OUTFLOW, '20.05', $today->setTime(18, 30));
        $this->persistTransaction($company, $mainAccount, $categories[CashflowCategory::CODE_INVESTING], CashDirection::OUTFLOW, '50.25', $today);
        $this->persistTransaction($company, $mainAccount, $categories[CashflowCategory::CODE_UNALLOCATED], CashDirection::INFLOW, '30.00', $today);
        $this->persistTransaction($company, $mainAccount, $categories[CashflowCategory::CODE_TECHNICAL_IN], CashDirection::INFLOW, '2000.00', $today);
        $this->persistTransaction($company, $mainAccount, $categories[CashflowCategory::CODE_OPERATING], CashDirection::INFLOW, '3000.00', $today)->setIsTransfer(true);
        $this->persistTransaction($company, $mainAccount, $categories[CashflowCategory::CODE_OPERATING], CashDirection::OUTFLOW, '4000.00', $today)->markDeleted(null);
        $this->persistTransaction($company, $inactiveAccount, $categories[CashflowCategory::CODE_OPERATING], CashDirection::INFLOW, '11.11', $today);
        $this->persistTransaction($company, $usdAccount, $categories[CashflowCategory::CODE_OPERATING], CashDirection::INFLOW, '5000.00', $today, 'USD');
        $this->persistTransaction($foreignCompany, $foreignAccount, $foreignCategories[CashflowCategory::CODE_OPERATING], CashDirection::INFLOW, '6000.00', $today);
        $this->em->flush();

        $result = self::getContainer()->get(FinanceBalanceDynamicsProvider::class)->build(
            $company,
            FiatCurrency::RUB,
            30,
            $today,
        );

        self::assertSame('2026-07-17', $result['from']->format('Y-m-d'));
        self::assertSame('2026-08-15', $result['to']->format('Y-m-d'));
        self::assertCount(30, $result['points']);
        self::assertTrue($result['minimumBalance']?->equals(Money::fromString('75.00', 'RUB')));

        $points = array_column($result['points'], null, 'date');
        self::assertSame('80.00', $points['2026-07-17']['balance']);
        self::assertSame('80.00', $points['2026-08-09']['balance']);
        self::assertSame('90.00', $points['2026-08-10']['balance']);
        self::assertSame('70.00', $points['2026-08-14']['balance']);
        self::assertTrue($points['2026-08-14']['belowMinimum']);
        self::assertSame('100.00', $points['2026-08-15']['balance']);
        self::assertFalse($points['2026-08-15']['belowMinimum']);
        self::assertSame([
            'operating' => '51.16',
            'financing' => '40.15',
            'investing' => '-50.25',
        ], $points['2026-08-15']['flows']);
        self::assertSame([
            'operating' => '0.00',
            'financing' => '0.00',
            'investing' => '0.00',
        ], $points['2026-08-14']['flows']);

        $reportBuilder = self::getContainer()->get(CashflowReportBuilder::class);
        foreach (['operating' => '51.16', 'financing' => '40.15', 'investing' => '-50.25'] as $activity => $expected) {
            $report = $reportBuilder->build(new CashflowReportParams(
                $company,
                'day',
                $today,
                $today,
                dashboardActivity: $activity,
                dashboardCurrency: FiatCurrency::RUB,
            ));
            self::assertSame($expected, $report['dashboardReconciliation']['net']);
        }

        $usd = self::getContainer()->get(FinanceBalanceDynamicsProvider::class)->build(
            $company,
            FiatCurrency::USD,
            30,
            $today,
        );
        self::assertNull($usd['minimumBalance']);
        self::assertSame('500.00', $usd['points'][29]['balance']);
    }

    public function testBuildReturnsEmptyPointsWithoutActiveAccounts(): void
    {
        $user = UserBuilder::aUser()->withEmail('empty-balance-dynamics@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        $result = self::getContainer()->get(FinanceBalanceDynamicsProvider::class)->build(
            $company,
            FiatCurrency::RUB,
            30,
            new \DateTimeImmutable('2026-08-15'),
        );

        self::assertSame([], $result['points']);
    }

    public function testBuildDoesNotFlagDaysBeforeFirstAccountOpening(): void
    {
        $user = UserBuilder::aUser()->withEmail('recent-balance-dynamics@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $company->setMinimumBalance(Money::fromString('75.00', 'RUB'));
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Recently opened account')
            ->build()
            ->setOpeningBalance('10.00')
            ->setOpeningBalanceDate(new \DateTimeImmutable('2026-08-10'));
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        $result = self::getContainer()->get(FinanceBalanceDynamicsProvider::class)->build(
            $company,
            FiatCurrency::RUB,
            30,
            new \DateTimeImmutable('2026-08-15'),
        );
        $points = array_column($result['points'], null, 'date');

        self::assertSame('0.00', $points['2026-08-09']['balance']);
        self::assertFalse($points['2026-08-09']['belowMinimum']);
        self::assertSame('10.00', $points['2026-08-10']['balance']);
        self::assertTrue($points['2026-08-10']['belowMinimum']);
    }

    private function persistBalance(
        Company $company,
        MoneyAccount $account,
        string $date,
        string $balance,
        string $currency,
    ): void {
        $this->em->persist(new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            new \DateTimeImmutable($date),
            $balance,
            '0.00',
            '0.00',
            $balance,
            $currency,
        ));
    }

    private function persistTransaction(
        Company $company,
        MoneyAccount $account,
        CashflowCategory $category,
        CashDirection $direction,
        string $amount,
        \DateTimeImmutable $date,
        string $currency = 'RUB',
    ): CashTransaction {
        return $this->persistTransactionWithSplits(
            $company,
            $account,
            $direction,
            $amount,
            $date,
            [[$category, $amount]],
            $currency,
        );
    }

    /** @param list<array{0:CashflowCategory,1:string}> $splits */
    private function persistTransactionWithSplits(
        Company $company,
        MoneyAccount $account,
        CashDirection $direction,
        string $amount,
        \DateTimeImmutable $date,
        array $splits,
        string $currency = 'RUB',
    ): CashTransaction {
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            $direction,
            $amount,
            $currency,
            $date,
        );
        $transaction->replaceSplits(array_map(
            static fn (array $split): CashTransactionSplit => new CashTransactionSplit(
                $transaction,
                $split[0],
                $split[1],
                CashTransactionSplitSource::MANUAL,
            ),
            $splits,
        ));
        $this->em->persist($transaction);

        return $transaction;
    }
}
