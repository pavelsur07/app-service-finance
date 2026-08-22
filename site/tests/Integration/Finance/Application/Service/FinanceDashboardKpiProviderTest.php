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

    public function testBuildFiltersTurnoverBySplitActivityAndTransactionScope(): void
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
