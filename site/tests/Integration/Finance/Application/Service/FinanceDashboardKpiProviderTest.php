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

    private function persistTransaction(
        Company $company,
        MoneyAccount $account,
        CashflowCategory $category,
        CashDirection $direction,
        string $amount,
        \DateTimeImmutable $occurredAt,
    ): void {
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            $direction,
            $amount,
            'RUB',
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
    }
}
