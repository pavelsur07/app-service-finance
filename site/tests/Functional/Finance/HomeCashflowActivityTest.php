<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Company\Entity\Company;
use App\Shared\Service\UiModeResolver;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

final class HomeCashflowActivityTest extends WebTestCaseBase
{
    /**
     * @var array<string, array{inflow30: string, outflow30: string, netFlow30: string}>
     */
    private const EXPECTED_BY_ACTIVITY = [
        'operating' => ['inflow30' => '11RUB', 'outflow30' => '4RUB', 'netFlow30' => '7RUB'],
        'financing' => ['inflow30' => '20RUB', 'outflow30' => '5RUB', 'netFlow30' => '15RUB'],
        'investing' => ['inflow30' => '30RUB', 'outflow30' => '40RUB', 'netFlow30' => '−10RUB'],
        'all' => ['inflow30' => '101RUB', 'outflow30' => '56RUB', 'netFlow30' => '45RUB'],
    ];

    /**
     * @var array<string, array<string, array{state:string,variant:string,badge:string,previous:string}>>
     */
    private const EXPECTED_COMPARISONS_BY_ACTIVITY = [
        'operating' => [
            'inflow30' => ['state' => 'percent', 'variant' => 'down', 'badge' => '−98,6%', 'previous' => '770RUB'],
            'outflow30' => ['state' => 'percent', 'variant' => 'up', 'badge' => '−99,5%', 'previous' => '800RUB'],
            'netFlow30' => ['state' => 'cross_up', 'variant' => 'up', 'badge' => 'Изминусавплюс', 'previous' => '−30RUB'],
        ],
        'financing' => [
            'inflow30' => ['state' => 'percent', 'variant' => 'up', 'badge' => '+100,0%', 'previous' => '10RUB'],
            'outflow30' => ['state' => 'no_base', 'variant' => 'neutral', 'badge' => 'Нетбазы', 'previous' => '0RUB'],
            'netFlow30' => ['state' => 'percent', 'variant' => 'up', 'badge' => '+50,0%', 'previous' => '10RUB'],
        ],
        'investing' => [
            'inflow30' => ['state' => 'percent', 'variant' => 'down', 'badge' => '−40,0%', 'previous' => '50RUB'],
            'outflow30' => ['state' => 'no_base', 'variant' => 'neutral', 'badge' => 'Нетбазы', 'previous' => '0RUB'],
            'netFlow30' => ['state' => 'cross_down', 'variant' => 'down', 'badge' => 'Изплюсавминус', 'previous' => '50RUB'],
        ],
        'all' => [
            'inflow30' => ['state' => 'percent', 'variant' => 'down', 'badge' => '−87,8%', 'previous' => '830RUB'],
            'outflow30' => ['state' => 'percent', 'variant' => 'up', 'badge' => '−93,0%', 'previous' => '800RUB'],
            'netFlow30' => ['state' => 'percent', 'variant' => 'up', 'badge' => '+50,0%', 'previous' => '30RUB'],
        ],
    ];

    public function testCashflowActivityFilterInBothUiModes(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $user = UserBuilder::aUser()->withEmail('home-cashflow-activity@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build()
            ->setOpeningBalance('1000.00');
        foreach ([$user, $company, $account] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $categories = self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        $today = new \DateTimeImmutable('today');
        $yesterday = $today->modify('-1 day');
        $currentPeriodStart = $today->modify('-29 days');
        $previousPeriodEnd = $today->modify('-30 days');
        $previousPeriodStart = $today->modify('-59 days');
        $previousPeriodMiddle = $today->modify('-45 days');
        $outsideComparisonPeriods = $today->modify('-60 days');
        foreach ([
            [CashflowCategory::CODE_OPERATING, CashDirection::INFLOW, '10.00', $today],
            [CashflowCategory::CODE_OPERATING, CashDirection::OUTFLOW, '4.00', $yesterday],
            [CashflowCategory::CODE_OPERATING, CashDirection::INFLOW, '1.00', $currentPeriodStart],
            [CashflowCategory::CODE_OPERATING, CashDirection::INFLOW, '70.00', $previousPeriodEnd],
            [CashflowCategory::CODE_OPERATING, CashDirection::INFLOW, '700.00', $previousPeriodStart],
            [CashflowCategory::CODE_OPERATING, CashDirection::OUTFLOW, '800.00', $previousPeriodMiddle],
            [CashflowCategory::CODE_OPERATING, CashDirection::INFLOW, '7000.00', $outsideComparisonPeriods],
            [CashflowCategory::CODE_FINANCING, CashDirection::INFLOW, '20.00', $today],
            [CashflowCategory::CODE_FINANCING, CashDirection::OUTFLOW, '5.00', $yesterday],
            [CashflowCategory::CODE_FINANCING, CashDirection::INFLOW, '10.00', $previousPeriodMiddle],
            [CashflowCategory::CODE_INVESTING, CashDirection::INFLOW, '30.00', $today],
            [CashflowCategory::CODE_INVESTING, CashDirection::OUTFLOW, '40.00', $yesterday],
            [CashflowCategory::CODE_INVESTING, CashDirection::INFLOW, '50.00', $previousPeriodMiddle],
            [CashflowCategory::CODE_UNALLOCATED, CashDirection::INFLOW, '40.00', $today],
            [CashflowCategory::CODE_UNALLOCATED, CashDirection::OUTFLOW, '7.00', $yesterday],
            [CashflowCategory::CODE_TECHNICAL_IN, CashDirection::INFLOW, '50.00', $today],
            [CashflowCategory::CODE_TECHNICAL_OUT, CashDirection::OUTFLOW, '8.00', $yesterday],
        ] as [$categoryCode, $direction, $amount, $occurredAt]) {
            $this->persistTransaction($company, $account, $categories[$categoryCode], $direction, $amount, $occurredAt);
        }
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        foreach ([UiModeResolver::LEGACY, UiModeResolver::APP] as $uiMode) {
            $client->getCookieJar()->set(new Cookie(UiModeResolver::COOKIE_NAME, $uiMode));

            foreach (array_keys(self::EXPECTED_BY_ACTIVITY) as $activity) {
                $crawler = $client->request('GET', '/finance?currency=RUB&activity='.$activity);

                self::assertResponseIsSuccessful();
                $this->assertActivityState($crawler, $activity);
                $this->assertKpis($crawler, self::EXPECTED_BY_ACTIVITY[$activity]);
                $this->assertKpiComparisons($crawler, $uiMode, $activity);
            }

            $defaultCrawler = $client->request('GET', '/finance?currency=RUB');
            self::assertResponseIsSuccessful();
            $this->assertActivityState($defaultCrawler, 'all');
            $this->assertKpis($defaultCrawler, self::EXPECTED_BY_ACTIVITY['all']);
            $this->assertTabsComposition($defaultCrawler, $uiMode);

            $invalidCrawler = $client->request('GET', '/finance?currency=RUB&activity=unknown');
            self::assertResponseIsSuccessful();
            $this->assertActivityState($invalidCrawler, 'all');
            $this->assertKpis($invalidCrawler, self::EXPECTED_BY_ACTIVITY['all']);
        }
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
        $this->em()->persist($transaction);
    }

    /**
     * @param array{inflow30: string, outflow30: string, netFlow30: string} $expected
     */
    private function assertKpis(Crawler $crawler, array $expected): void
    {
        self::assertSame('1000RUB', $this->normalizedKpi($crawler, 'todayBalance'));
        foreach ($expected as $name => $value) {
            self::assertSame($value, $this->normalizedKpi($crawler, $name));
        }
    }

    private function assertActivityState(Crawler $crawler, string $activity): void
    {
        $filter = $crawler->filter('[data-dashboard-activity-filter]');
        self::assertCount(1, $filter);
        self::assertSame($activity, $filter->attr('data-selected-activity'));
        self::assertSame(
            ['Операционная', 'Финансовая', 'Инвестиционная', 'Общая'],
            $filter->filter('button[name="activity"]')->each(static fn ($node): string => trim($node->text())),
        );
        self::assertCount(1, $filter->filter(sprintf('button[value="%s"][aria-pressed="true"]', $activity)));
        self::assertCount(1, $filter->filter('input[name="currency"][value="RUB"]'));

        if (1 === $crawler->filter('html[data-ui-mode="app"]')->count()) {
            self::assertCount(1, $crawler->filter(sprintf(
                'form.wz-head-actions input[name="activity"][value="%s"]',
                $activity,
            )));
        }
    }

    private function assertKpiComparisons(Crawler $crawler, string $uiMode, string $activity): void
    {
        if (UiModeResolver::LEGACY === $uiMode) {
            self::assertCount(0, $crawler->filter('[data-dashboard-kpi-comparison]'));

            return;
        }

        self::assertCount(4, $crawler->filter('[data-dashboard-kpi-comparison]'));
        $balanceCard = $crawler->filter('[data-dashboard-kpi="todayBalance"]')->closest('article.kpi');
        self::assertNotNull($balanceCard);
        $balance = $balanceCard->filter('[data-dashboard-kpi-comparison="todayBalance"]');
        self::assertCount(1, $balance);
        self::assertSame('percent', $balance->attr('data-comparison-state'));
        self::assertSame('neutral', $balance->attr('data-comparison-variant'));
        self::assertCount(1, $balance->filter('.delta.delta--neutral'));
        self::assertSame('0,0%', $this->normalizedText($balance->filter('.delta')));
        self::assertStringContainsString('30днейназад:1000RUB', $this->normalizedText($balance));

        foreach (self::EXPECTED_COMPARISONS_BY_ACTIVITY[$activity] as $name => $expected) {
            $card = $crawler->filter(sprintf('[data-dashboard-kpi="%s"]', $name))->closest('article.kpi');
            self::assertNotNull($card);
            $comparison = $card->filter(sprintf('[data-dashboard-kpi-comparison="%s"]', $name));
            self::assertCount(1, $comparison);
            self::assertSame($expected['state'], $comparison->attr('data-comparison-state'));
            self::assertSame($expected['variant'], $comparison->attr('data-comparison-variant'));
            self::assertCount(1, $comparison->filter('.delta.delta--'.$expected['variant']));
            self::assertStringContainsString($expected['badge'], $this->normalizedText($comparison));
            if ('percent' === $expected['state']) {
                self::assertStringContainsString(
                    str_starts_with($expected['badge'], '+') ? 'Ростна' : 'Снижениена',
                    $this->normalizedText($comparison->filter('.delta')),
                );
                self::assertCount(1, $comparison->filter('.delta [aria-hidden="true"]'));
            }
            self::assertStringContainsString(
                'Предыдущие30дней:'.$expected['previous'],
                $this->normalizedText($comparison),
            );
        }
    }

    private function assertTabsComposition(Crawler $crawler, string $uiMode): void
    {
        if (UiModeResolver::LEGACY === $uiMode) {
            self::assertCount(0, $crawler->filter('.tabs-meta'));

            return;
        }

        $tabsRow = $crawler->filter('.wz-row > .tabs-row');
        self::assertCount(1, $tabsRow);
        self::assertCount(1, $tabsRow->children('form.tabs + .u-flex-1'));
        self::assertCount(1, $tabsRow->children('.u-flex-1 + .tabs-meta'));
        self::assertCount(1, $tabsRow->filter('.tabs-meta > svg[aria-hidden="true"]'));
        self::assertSame(
            'Данные на '.(new \DateTimeImmutable('today'))->format('d.m.Y'),
            $tabsRow->filter('.tabs-meta')->text(),
        );
    }

    private function normalizedKpi(Crawler $crawler, string $name): string
    {
        $value = $crawler->filter(sprintf('[data-dashboard-kpi="%s"]', $name));
        self::assertCount(1, $value);

        return $this->normalizedText($value);
    }

    private function normalizedText(Crawler $crawler): string
    {
        // Legacy number_format emits ASCII '-', while the app money macro uses U+2212.
        return str_replace('-', '−', (string) preg_replace('/\s+/u', '', $crawler->text()));
    }
}
