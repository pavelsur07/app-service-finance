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
use App\Finance\Application\Service\FinanceDashboardKpiProvider;
use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportParams;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class FinanceDashboardKpiProviderTest extends IntegrationTestCase
{
    public function testBuildCalculatesCurrentAndPreviousKpisByActivity(): void
    {
        $today = new \DateTimeImmutable('2026-08-15');
        $user = UserBuilder::aUser()->withEmail('finance-dashboard-kpi@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $mainAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Main account')
            ->build()
            ->setOpeningBalance('0.00');
        $recentAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Recent account')
            ->build()
            ->setOpeningBalance('30.00')
            ->setOpeningBalanceDate($today->modify('-10 days'));
        $futureAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Future account')
            ->build()
            ->setOpeningBalance('500.00')
            ->setOpeningBalanceDate($today->modify('+1 day'));

        foreach ([$user, $company, $mainAccount, $recentAccount, $futureAccount] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $mainAccount,
            $today->modify('-30 days'),
            '-100.00',
            '0.00',
            '0.00',
            '-100.00',
            'RUB',
        ));
        $this->em->persist(new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $mainAccount,
            $today,
            '50.00',
            '0.00',
            '0.00',
            '50.00',
            'RUB',
        ));
        $this->em->flush();

        $categories = self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        foreach ([
            [CashflowCategory::CODE_OPERATING, CashDirection::INFLOW, '100.00', $today],
            [CashflowCategory::CODE_OPERATING, CashDirection::OUTFLOW, '60.00', $today->modify('-1 day')],
            [CashflowCategory::CODE_OPERATING, CashDirection::INFLOW, '40.00', $today->modify('-30 days')],
            [CashflowCategory::CODE_OPERATING, CashDirection::OUTFLOW, '80.00', $today->modify('-59 days')],
            [CashflowCategory::CODE_OPERATING, CashDirection::INFLOW, '9999.00', $today->modify('-60 days')],
            [CashflowCategory::CODE_FINANCING, CashDirection::INFLOW, '1000.50', $today],
            [CashflowCategory::CODE_FINANCING, CashDirection::INFLOW, '1000.00', $today->modify('-30 days')],
            [CashflowCategory::CODE_INVESTING, CashDirection::OUTFLOW, '50.00', $today],
            [CashflowCategory::CODE_INVESTING, CashDirection::INFLOW, '50.00', $today->modify('-30 days')],
            [CashflowCategory::CODE_UNALLOCATED, CashDirection::INFLOW, '10.00', $today],
            [CashflowCategory::CODE_UNALLOCATED, CashDirection::INFLOW, '20.00', $today->modify('-30 days')],
            [CashflowCategory::CODE_TECHNICAL_IN, CashDirection::INFLOW, '5000.00', $today],
            [CashflowCategory::CODE_TECHNICAL_OUT, CashDirection::OUTFLOW, '5000.00', $today->modify('-30 days')],
        ] as [$categoryCode, $direction, $amount, $occurredAt]) {
            $this->persistTransaction(
                $company,
                $mainAccount,
                $categories[$categoryCode],
                $direction,
                $amount,
                $occurredAt,
            );
        }
        $this->em->flush();

        $provider = self::getContainer()->get(FinanceDashboardKpiProvider::class);

        $operating = $provider->build($company, FiatCurrency::RUB, 'operating', true, $today);
        self::assertSame([
            'todayBalance' => '80.00',
            'inflow30' => '100.00',
            'outflow30' => '60.00',
            'netFlow30' => '40.00',
        ], $operating['kpi']);
        self::assertSame([
            'todayBalance' => ['previous' => '-100.00', 'state' => 'cross_up', 'percent' => null, 'variant' => 'up'],
            'inflow30' => ['previous' => '40.00', 'state' => 'percent', 'percent' => '150.0', 'variant' => 'up'],
            'outflow30' => ['previous' => '80.00', 'state' => 'percent', 'percent' => '-25.0', 'variant' => 'up'],
            'netFlow30' => ['previous' => '-40.00', 'state' => 'cross_up', 'percent' => null, 'variant' => 'up'],
        ], $operating['comparisons']);
        self::assertSame('2026-07-17', $operating['periods']['current']['from']->format('Y-m-d'));
        self::assertSame('2026-08-15', $operating['periods']['current']['to']->format('Y-m-d'));
        self::assertSame('2026-06-17', $operating['periods']['previous']['from']->format('Y-m-d'));
        self::assertSame('2026-07-16', $operating['periods']['previous']['to']->format('Y-m-d'));
        self::assertSame('2026-07-16', $operating['periods']['balanceComparisonDate']->format('Y-m-d'));

        $financing = $provider->build($company, FiatCurrency::RUB, 'financing', true, $today);
        self::assertSame(
            ['previous' => '1000.00', 'state' => 'percent', 'percent' => '0.1', 'variant' => 'up'],
            $financing['comparisons']['inflow30'],
        );
        self::assertSame(
            ['previous' => '0.00', 'state' => 'no_base', 'percent' => null, 'variant' => 'neutral'],
            $financing['comparisons']['outflow30'],
        );

        $investing = $provider->build($company, FiatCurrency::RUB, 'investing', true, $today);
        self::assertSame(
            ['previous' => '50.00', 'state' => 'cross_down', 'percent' => null, 'variant' => 'down'],
            $investing['comparisons']['netFlow30'],
        );

        $all = $provider->build($company, FiatCurrency::RUB, 'all', true, $today);
        self::assertSame([
            'todayBalance' => '80.00',
            'inflow30' => '1110.50',
            'outflow30' => '110.00',
            'netFlow30' => '1000.50',
        ], $all['kpi']);
        self::assertSame('1110.00', $all['comparisons']['inflow30']['previous']);
        self::assertSame('0.0', $all['comparisons']['inflow30']['percent']);
        self::assertSame('neutral', $all['comparisons']['inflow30']['variant']);
        self::assertSame('80.00', $all['comparisons']['outflow30']['previous']);
        self::assertSame('1030.00', $all['comparisons']['netFlow30']['previous']);
        self::assertSame('-2.9', $all['comparisons']['netFlow30']['percent']);
        self::assertSame('down', $all['comparisons']['netFlow30']['variant']);

        $legacy = $provider->build($company, FiatCurrency::RUB, 'all', false, $today);
        self::assertSame($all['kpi'], $legacy['kpi']);
        self::assertSame([], $legacy['comparisons']);
        self::assertEquals($all['periods'], $legacy['periods']);
    }

    public function testBuildSupportsSevenFourteenAndThirtyDayPeriods(): void
    {
        $today = new \DateTimeImmutable('2026-08-15');
        $user = UserBuilder::aUser()->withEmail('finance-dashboard-periods@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build()
            ->setOpeningBalance('0.00');

        foreach ([$user, $company, $account] as $entity) {
            $this->em->persist($entity);
        }
        foreach ([0 => '100.00', -7 => '7.00', -14 => '14.00', -30 => '30.00'] as $days => $balance) {
            $this->em->persist(new MoneyAccountDailyBalance(
                Uuid::uuid4()->toString(),
                $company,
                $account,
                $today->modify($days.' days'),
                $balance,
                '0.00',
                '0.00',
                $balance,
                'RUB',
            ));
        }
        $this->em->flush();

        $categories = self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        foreach ([
            0 => '1.00',
            -6 => '2.00',
            -7 => '4.00',
            -13 => '8.00',
            -14 => '16.00',
            -27 => '32.00',
            -28 => '64.00',
            -29 => '128.00',
            -30 => '256.00',
            -59 => '512.00',
            -60 => '1024.00',
        ] as $days => $amount) {
            $this->persistTransaction(
                $company,
                $account,
                $categories[CashflowCategory::CODE_OPERATING],
                CashDirection::INFLOW,
                $amount,
                $today->modify($days.' days'),
            );
        }
        $this->em->flush();

        $provider = self::getContainer()->get(FinanceDashboardKpiProvider::class);
        $expectations = [
            7 => ['2026-08-09', '2026-08-02', '2026-08-08', '3.00', '12.00', '7.00'],
            14 => ['2026-08-02', '2026-07-19', '2026-08-01', '15.00', '48.00', '14.00'],
            30 => ['2026-07-17', '2026-06-17', '2026-07-16', '255.00', '768.00', '30.00'],
        ];

        foreach ($expectations as $periodDays => [$currentFrom, $previousFrom, $previousTo, $inflow, $previousInflow, $previousBalance]) {
            $result = $provider->build(
                $company,
                FiatCurrency::RUB,
                'operating',
                true,
                $today,
                periodDays: $periodDays,
            );

            self::assertSame('100.00', $result['kpi']['todayBalance']);
            self::assertSame($inflow, $result['kpi']['inflow30']);
            self::assertSame($previousInflow, $result['comparisons']['inflow30']['previous']);
            self::assertSame($previousBalance, $result['comparisons']['todayBalance']['previous']);
            self::assertSame($currentFrom, $result['periods']['current']['from']->format('Y-m-d'));
            self::assertSame('2026-08-15', $result['periods']['current']['to']->format('Y-m-d'));
            self::assertSame($previousFrom, $result['periods']['previous']['from']->format('Y-m-d'));
            self::assertSame($previousTo, $result['periods']['previous']['to']->format('Y-m-d'));
            self::assertSame($previousTo, $result['periods']['balanceComparisonDate']->format('Y-m-d'));
        }

        $default = $provider->build($company, FiatCurrency::RUB, 'operating', true, $today);
        self::assertEquals($default, $provider->build(
            $company,
            FiatCurrency::RUB,
            'operating',
            true,
            $today,
            periodDays: 30,
        ));

        foreach ([0, -1] as $invalidPeriodDays) {
            try {
                $provider->build(
                    $company,
                    FiatCurrency::RUB,
                    'operating',
                    true,
                    $today,
                    periodDays: $invalidPeriodDays,
                );
                self::fail(sprintf('Period %d should be rejected.', $invalidPeriodDays));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    sprintf('Period days must be positive, got %d.', $invalidPeriodDays),
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testBuildKeepsGrossTurnoverWhenParentAndChildHaveOppositeDirections(): void
    {
        $today = new \DateTimeImmutable('2026-08-15');
        $user = UserBuilder::aUser()->withEmail('finance-dashboard-mixed-directions@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Mixed directions account')
            ->build()
            ->setOpeningBalance('0.00');

        foreach ([$user, $company, $account] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $categories = self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        $child = (new CashflowCategory(Uuid::uuid4()->toString(), $company))
            ->setName('Operating child')
            ->setParent($categories[CashflowCategory::CODE_OPERATING])
            ->syncFlowKindWithParent();
        $this->em->persist($child);

        $this->persistTransaction(
            $company,
            $account,
            $categories[CashflowCategory::CODE_OPERATING],
            CashDirection::INFLOW,
            '100.00',
            $today,
        );
        $this->persistTransaction(
            $company,
            $account,
            $child,
            CashDirection::OUTFLOW,
            '80.00',
            $today,
        );
        $this->em->flush();

        $result = self::getContainer()->get(FinanceDashboardKpiProvider::class)->build(
            $company,
            FiatCurrency::RUB,
            'operating',
            false,
            $today,
        );

        self::assertSame('100.00', $result['kpi']['inflow30']);
        self::assertSame('80.00', $result['kpi']['outflow30']);
        self::assertSame('20.00', $result['kpi']['netFlow30']);

        $report = self::getContainer()->get(CashflowReportBuilder::class)->build(
            new CashflowReportParams($company, 'day', $today, $today),
        );
        $reportNet = $report['categoryTotals'][(string) $categories[CashflowCategory::CODE_OPERATING]->getId()]['totals']['RUB'][0];
        self::assertSame(20.0, $reportNet);
        self::assertSame($result['kpi']['netFlow30'], number_format($reportNet, 2, '.', ''));
    }

    public function testBuildFiltersTurnoverAndCashflowReconciliationBySplitActivityAndTransactionScope(): void
    {
        $today = new \DateTimeImmutable('2026-08-15');
        $user = UserBuilder::aUser()->withEmail('finance-dashboard-filters@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $rubAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('RUB account')
            ->build()
            ->setOpeningBalance('0.00');
        $usdAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('USD account')
            ->withCurrency('USD')
            ->build()
            ->setOpeningBalance('0.00');
        $foreignUser = UserBuilder::aUser()->withIndex(2)->build();
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($foreignUser)->build();
        $foreignAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($foreignCompany)
            ->withName('Foreign company account')
            ->build()
            ->setOpeningBalance('0.00');

        foreach ([$user, $company, $rubAccount, $usdAccount, $foreignUser, $foreignCompany, $foreignAccount] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $categories = self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        $this->persistTransaction(
            $company,
            $rubAccount,
            $categories[CashflowCategory::CODE_OPERATING],
            CashDirection::INFLOW,
            '100.00',
            $today,
        );
        $this->persistTransaction(
            $company,
            $rubAccount,
            $categories[CashflowCategory::CODE_OPERATING],
            CashDirection::INFLOW,
            '1000.00',
            $today,
        )->setIsTransfer(true);
        $this->persistTransaction(
            $company,
            $rubAccount,
            $categories[CashflowCategory::CODE_TECHNICAL_IN],
            CashDirection::INFLOW,
            '2000.00',
            $today,
        );
        $this->persistTransaction(
            $company,
            $rubAccount,
            $categories[CashflowCategory::CODE_UNALLOCATED],
            CashDirection::INFLOW,
            '30.00',
            $today,
        );
        $this->persistTransaction(
            $company,
            $rubAccount,
            $categories[CashflowCategory::CODE_OPERATING],
            CashDirection::OUTFLOW,
            '500.00',
            $today,
        )->markDeleted(null);
        $this->persistTransaction(
            $company,
            $usdAccount,
            $categories[CashflowCategory::CODE_OPERATING],
            CashDirection::INFLOW,
            '700.00',
            $today,
            'USD',
        );
        $foreignCategories = self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($foreignCompany);
        $this->persistTransaction(
            $foreignCompany,
            $foreignAccount,
            $foreignCategories[CashflowCategory::CODE_OPERATING],
            CashDirection::INFLOW,
            '900.00',
            $today,
        );
        $this->em->persist(new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $rubAccount,
            CashDirection::INFLOW,
            '800.00',
            'RUB',
            $today,
        ));

        $splitTransaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $rubAccount,
            CashDirection::INFLOW,
            '100.00',
            'RUB',
            $today,
        );
        $splitTransaction->replaceSplits([
            new CashTransactionSplit(
                $splitTransaction,
                $categories[CashflowCategory::CODE_OPERATING],
                '60.00',
                CashTransactionSplitSource::MANUAL,
            ),
            new CashTransactionSplit(
                $splitTransaction,
                $categories[CashflowCategory::CODE_FINANCING],
                '40.00',
                CashTransactionSplitSource::MANUAL,
            ),
        ]);
        $this->em->persist($splitTransaction);
        $this->em->flush();

        $provider = self::getContainer()->get(FinanceDashboardKpiProvider::class);
        $operating = $provider->build($company, FiatCurrency::RUB, 'operating', false, $today);
        $financing = $provider->build($company, FiatCurrency::RUB, 'financing', false, $today);
        $all = $provider->build($company, FiatCurrency::RUB, 'all', false, $today);

        self::assertSame('160.00', $operating['kpi']['inflow30']);
        self::assertSame('0.00', $operating['kpi']['outflow30']);
        self::assertSame('40.00', $financing['kpi']['inflow30']);
        self::assertSame('230.00', $all['kpi']['inflow30']);
        self::assertSame('0.00', $all['kpi']['outflow30']);
        self::assertSame('230.00', $all['kpi']['netFlow30']);

        $reportBuilder = self::getContainer()->get(CashflowReportBuilder::class);
        $operatingReport = $reportBuilder->build(new CashflowReportParams(
            company: $company,
            group: 'month',
            from: $operating['periods']['current']['from'],
            to: $operating['periods']['current']['to'],
            dashboardActivity: 'operating',
            dashboardCurrency: FiatCurrency::RUB,
        ));
        self::assertSame([
            'mode' => 'dashboard',
            'activity' => 'operating',
            'currency' => 'RUB',
            'inflow' => $operating['kpi']['inflow30'],
            'outflow' => $operating['kpi']['outflow30'],
            'net' => $operating['kpi']['netFlow30'],
        ], $operatingReport['dashboardReconciliation']);
        self::assertArrayHasKey(
            (string) $categories[CashflowCategory::CODE_OPERATING]->getId(),
            $operatingReport['categoryTotals'],
        );
        self::assertArrayNotHasKey(
            (string) $categories[CashflowCategory::CODE_UNALLOCATED]->getId(),
            $operatingReport['categoryTotals'],
        );
        self::assertArrayNotHasKey(
            (string) $categories[CashflowCategory::CODE_TECHNICAL_IN]->getId(),
            $operatingReport['categoryTotals'],
        );
        $operatingCategoryId = (string) $categories[CashflowCategory::CODE_OPERATING]->getId();
        self::assertSame(
            [0.0, 160.0],
            $operatingReport['categoryTotals'][$operatingCategoryId]['totals']['RUB'],
        );
        self::assertSame(
            ['RUB'],
            array_keys($operatingReport['categoryTotals'][$operatingCategoryId]['totals']),
        );
        self::assertCount(1, $operatingReport['projectCenterMatrix']['rowsByProject']);
        self::assertSame(
            [0.0, 160.0],
            $operatingReport['projectCenterMatrix']['rowsByProject'][0]['totals']['RUB'],
        );
        self::assertSame([0.0, 3230.0], $operatingReport['closings']['RUB']);
        self::assertArrayNotHasKey('USD', $operatingReport['closings']);

        $allReport = $reportBuilder->build(new CashflowReportParams(
            company: $company,
            group: 'month',
            from: $all['periods']['current']['from'],
            to: $all['periods']['current']['to'],
            dashboardActivity: 'all',
            dashboardCurrency: FiatCurrency::RUB,
        ));
        self::assertSame($all['kpi']['inflow30'], $allReport['dashboardReconciliation']['inflow']);
        self::assertSame($all['kpi']['outflow30'], $allReport['dashboardReconciliation']['outflow']);
        self::assertSame($all['kpi']['netFlow30'], $allReport['dashboardReconciliation']['net']);
        self::assertArrayHasKey(
            (string) $categories[CashflowCategory::CODE_UNALLOCATED]->getId(),
            $allReport['categoryTotals'],
        );
        self::assertArrayNotHasKey(
            (string) $categories[CashflowCategory::CODE_TECHNICAL_IN]->getId(),
            $allReport['categoryTotals'],
        );
        self::assertSame(
            [0.0, 160.0],
            $allReport['categoryTotals'][$operatingCategoryId]['totals']['RUB'],
        );
        self::assertSame(
            [0.0, 40.0],
            $allReport['categoryTotals'][(string) $categories[CashflowCategory::CODE_FINANCING]->getId()]['totals']['RUB'],
        );
        self::assertSame(
            [0.0, 30.0],
            $allReport['categoryTotals'][(string) $categories[CashflowCategory::CODE_UNALLOCATED]->getId()]['totals']['RUB'],
        );
    }

    private function persistTransaction(
        Company $company,
        MoneyAccount $account,
        CashflowCategory $category,
        CashDirection $direction,
        string $amount,
        \DateTimeImmutable $occurredAt,
        string $currency = 'RUB',
    ): CashTransaction {
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            $direction,
            $amount,
            $currency,
            $occurredAt,
        );
        $transaction->setCashflowCategory($category);
        $transaction->replaceSplits([new CashTransactionSplit(
            $transaction,
            $category,
            $amount,
            CashTransactionSplitSource::MANUAL,
        )]);
        $this->em->persist($transaction);

        return $transaction;
    }
}
