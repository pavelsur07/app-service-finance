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
                $this->assertReconciliationLinks($crawler, $uiMode, $activity);
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

    public function testLegacyDashboardUsesSelectedPeriodWhileAppKeepsThirtyDays(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $user = UserBuilder::aUser()->withEmail('home-cashflow-period@example.test')->build();
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

        $today = new \DateTimeImmutable('today');
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
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->getCookieJar()->set(new Cookie(UiModeResolver::COOKIE_NAME, UiModeResolver::LEGACY));
        foreach ([7 => ['3RUB', '12RUB'], 14 => ['15RUB', '48RUB'], 30 => ['255RUB', '768RUB']] as $periodDays => [$current, $previous]) {
            $crawler = $client->request('GET', '/finance?currency=RUB&activity=operating&period='.$periodDays);
            self::assertResponseIsSuccessful();
            $this->assertPeriodState($crawler, $periodDays, $current, $previous);
            $this->assertLegacyFilterControls($crawler, $periodDays, 'operating');
        }

        foreach ([
            '/finance?currency=RUB&activity=operating',
            '/finance?currency=RUB&activity=operating&period=invalid',
            '/finance?currency=RUB&activity=operating&period=07',
            '/finance?currency=RUB&activity=operating&period=7.0',
            '/finance?currency=RUB&activity=operating&period=0',
            '/finance?currency=RUB&activity=operating&period=31',
            '/finance?currency=RUB&activity=operating&period=',
        ] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $this->assertPeriodState($crawler, 30, '255RUB', '768RUB');
            $this->assertLegacyFilterControls($crawler, 30, 'operating');
        }
        $arrayPeriod = $client->request('GET', '/finance', [
            'currency' => 'RUB',
            'activity' => 'operating',
            'period' => ['7'],
        ]);
        self::assertResponseIsSuccessful();
        $this->assertPeriodState($arrayPeriod, 30, '255RUB', '768RUB');
        $this->assertLegacyFilterControls($arrayPeriod, 30, 'operating');

        $interactive = $client->request('GET', '/finance?currency=RUB&activity=operating&period=7');
        $financing = $client->submit($interactive->selectButton('Финансовая')->form());
        self::assertResponseIsSuccessful();
        $this->assertLegacyFilterControls($financing, 7, 'financing');
        $fourteenDays = $client->submit($financing->selectButton('14 дней')->form());
        self::assertResponseIsSuccessful();
        $this->assertLegacyFilterControls($fourteenDays, 14, 'financing');

        $client->getCookieJar()->set(new Cookie(UiModeResolver::COOKIE_NAME, UiModeResolver::APP));
        $app = $client->request('GET', '/finance?currency=RUB&activity=operating&period=7');
        self::assertResponseIsSuccessful();
        $this->assertPeriodState($app, 30, '255RUB', '768RUB', UiModeResolver::APP);
        $this->assertActivityState($app, 'operating');
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

    private function assertActivityState(Crawler $crawler, string $activity, int $periodDays = 30): void
    {
        if (0 === $crawler->filter('html[data-ui-mode="app"]')->count()) {
            $this->assertLegacyFilterControls($crawler, $periodDays, $activity);

            return;
        }

        $filter = $crawler->filter('[data-dashboard-activity-filter]');
        self::assertCount(1, $filter);
        self::assertSame($activity, $filter->attr('data-selected-activity'));
        self::assertSame(
            ['Операционная', 'Финансовая', 'Инвестиционная', 'Общая'],
            $filter->filter('button[name="activity"]')->each(static fn ($node): string => trim($node->text())),
        );
        self::assertCount(1, $filter->filter(sprintf('button[value="%s"][aria-pressed="true"]', $activity)));
        self::assertCount(1, $filter->filter('input[name="currency"][value="RUB"]'));
        self::assertCount(0, $crawler->filter('[data-dashboard-filter-controls]'));
        self::assertCount(0, $crawler->filter('[data-dashboard-period-filter]'));
        self::assertCount(1, $crawler->filter(sprintf(
            'form.wz-head-actions input[name="activity"][value="%s"]',
            $activity,
        )));
    }

    private function assertLegacyFilterControls(Crawler $crawler, int $periodDays, string $activity): void
    {
        $controls = $crawler->filter('[data-dashboard-filter-controls].card.pl-preview-controls-card');
        self::assertCount(1, $controls);
        self::assertCount(1, $controls->filter('.card-body > .pl-preview-controls-row'));
        self::assertCount(2, $controls->filter('.pl-preview-control-group'));

        $activityFilter = $controls->filter('form[data-dashboard-activity-filter].pl-preview-control-group');
        self::assertCount(1, $activityFilter);
        self::assertSame($activity, $activityFilter->attr('data-selected-activity'));
        self::assertSame('Вид деятельности', trim($activityFilter->filter('.pl-preview-control-label')->text()));
        self::assertSame(
            ['Операционная', 'Финансовая', 'Инвестиционная', 'Общая'],
            $activityFilter->filter('.pl-preview-segmented button[name="activity"]')
                ->each(static fn ($node): string => trim($node->text())),
        );
        self::assertCount(1, $activityFilter->filter(sprintf(
            'button[name="activity"][value="%s"].is-active[aria-pressed="true"]',
            $activity,
        )));
        self::assertCount(1, $activityFilter->filter('.pl-preview-segmented[role="group"][aria-label="Вид деятельности"]'));
        self::assertCount(1, $activityFilter->filter('input[name="currency"][value="RUB"]'));
        self::assertCount(1, $activityFilter->filter(sprintf('input[name="period"][value="%d"]', $periodDays)));

        $periodFilter = $controls->filter('form[data-dashboard-period-filter].pl-preview-control-group');
        self::assertCount(1, $periodFilter);
        self::assertSame((string) $periodDays, $periodFilter->attr('data-selected-period'));
        self::assertSame('Период', trim($periodFilter->filter('.pl-preview-control-label')->text()));
        self::assertSame(
            ['7 дней', '14 дней', '30 дней'],
            $periodFilter->filter('.pl-preview-segmented button[name="period"]')
                ->each(static fn ($node): string => trim($node->text())),
        );
        self::assertCount(1, $periodFilter->filter(sprintf(
            'button[name="period"][value="%d"].is-active[aria-pressed="true"]',
            $periodDays,
        )));
        self::assertCount(1, $periodFilter->filter('.pl-preview-segmented[role="group"][aria-label="Период"]'));
        self::assertCount(1, $periodFilter->filter('input[name="currency"][value="RUB"]'));
        self::assertCount(1, $periodFilter->filter(sprintf('input[name="activity"][value="%s"]', $activity)));
    }

    private function assertKpiComparisons(Crawler $crawler, string $uiMode, string $activity): void
    {
        $today = new \DateTimeImmutable('today');
        $currentPeriodLabel = $this->dateRangeLabel($today->modify('-29 days'), $today);
        $previousPeriodLabel = $this->dateRangeLabel($today->modify('-59 days'), $today->modify('-30 days'));
        $balanceComparisonDate = $today->modify('-30 days');
        $balanceComparisonLabel = 'На '.$balanceComparisonDate->format(
            $balanceComparisonDate->format('Y') === $today->format('Y') ? 'd.m' : 'd.m.Y',
        );

        self::assertCount(4, $crawler->filter('[data-dashboard-kpi-comparison]'));
        $periodNodes = $crawler->filter('[data-dashboard-kpi-period="current"]');
        self::assertCount(3, $periodNodes);
        foreach ($periodNodes as $periodNode) {
            self::assertStringContainsString($currentPeriodLabel, $periodNode->textContent);
        }
        $cardSelector = UiModeResolver::LEGACY === $uiMode ? '.card' : 'article.kpi';
        $balanceCard = $crawler->filter('[data-dashboard-kpi="todayBalance"]')->closest($cardSelector);
        self::assertNotNull($balanceCard);
        $balance = $balanceCard->filter('[data-dashboard-kpi-comparison="todayBalance"]');
        self::assertCount(1, $balance);
        self::assertSame('percent', $balance->attr('data-comparison-state'));
        self::assertSame('neutral', $balance->attr('data-comparison-variant'));
        self::assertCount(1, $balance->filter(
            UiModeResolver::LEGACY === $uiMode ? '.kpi-trend.text-muted' : '.delta.delta--neutral',
        ));
        $balanceText = $this->normalizedText($balance);
        self::assertStringContainsString('0,0%', $balanceText);
        self::assertStringNotContainsString('+0,0%', $balanceText);
        self::assertStringNotContainsString('−0,0%', $balanceText);
        if (UiModeResolver::APP === $uiMode) {
            self::assertSame('0,0%', $this->normalizedText($balance->filter('.delta')));
        } else {
            self::assertCount(0, $balance->filter('.visually-hidden'));
        }
        self::assertStringContainsString(
            str_replace(' ', '', $balanceComparisonLabel).':1000RUB',
            $this->normalizedText($balance),
        );

        foreach (self::EXPECTED_COMPARISONS_BY_ACTIVITY[$activity] as $name => $expected) {
            $card = $crawler->filter(sprintf('[data-dashboard-kpi="%s"]', $name))->closest($cardSelector);
            self::assertNotNull($card);
            $comparison = $card->filter(sprintf('[data-dashboard-kpi-comparison="%s"]', $name));
            self::assertCount(1, $comparison);
            self::assertSame($expected['state'], $comparison->attr('data-comparison-state'));
            self::assertSame($expected['variant'], $comparison->attr('data-comparison-variant'));
            if (UiModeResolver::LEGACY === $uiMode) {
                $variantClass = match ($expected['variant']) {
                    'up' => 'text-success',
                    'down' => 'text-danger',
                    default => 'text-muted',
                };
                $variantSelector = '.kpi-trend.'.$variantClass;
            } else {
                $variantSelector = '.delta.delta--'.$expected['variant'];
            }
            self::assertCount(1, $comparison->filter($variantSelector));
            $expectedBadge = $expected['badge'];
            if (UiModeResolver::LEGACY === $uiMode) {
                if ('no_base' === $expected['state']) {
                    $expectedBadge = 'Изменение:—';
                    self::assertSame('Нетбазы', $this->normalizedText($comparison->filter('.visually-hidden')));
                } elseif ('percent' === $expected['state']) {
                    self::assertStringContainsString('Изменение:', $this->normalizedText($comparison));
                    if ('neutral' !== $expected['variant']) {
                        self::assertCount(1, $comparison->filter('.visually-hidden'));
                        self::assertCount(1, $comparison->filter('[aria-hidden="true"]'));
                        self::assertStringContainsString(
                            str_starts_with($expected['badge'], '+') ? 'ростна' : 'снижениена',
                            $this->normalizedText($comparison->filter('.visually-hidden')),
                        );
                    }
                } elseif (str_starts_with($expected['state'], 'cross_')) {
                    $expectedBadge = 'Изменение:'.$expectedBadge;
                }
            }
            self::assertStringContainsString($expectedBadge, $this->normalizedText($comparison));
            if ('percent' === $expected['state'] && UiModeResolver::APP === $uiMode) {
                self::assertStringContainsString(
                    str_starts_with($expected['badge'], '+') ? 'Ростна' : 'Снижениена',
                    $this->normalizedText($comparison->filter('.delta')),
                );
                self::assertCount(1, $comparison->filter('.delta [aria-hidden="true"]'));
            }
            self::assertStringContainsString(
                $previousPeriodLabel.':'.$expected['previous'],
                $this->normalizedText($comparison),
            );
        }
    }

    private function dateRangeLabel(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $format = $from->format('Y') === $to->format('Y') ? 'd.m' : 'd.m.Y';

        return $from->format($format).'–'.$to->format($format);
    }

    private function assertPeriodState(
        Crawler $crawler,
        int $periodDays,
        string $current,
        string $previous,
        string $uiMode = UiModeResolver::LEGACY,
    ): void {
        $today = new \DateTimeImmutable('today');
        $currentFrom = $today->modify(sprintf('-%d days', $periodDays - 1));
        $previousTo = $today->modify(sprintf('-%d days', $periodDays));
        $previousFrom = $today->modify(sprintf('-%d days', 2 * $periodDays - 1));

        self::assertSame($current, $this->normalizedKpi($crawler, 'inflow30'));
        self::assertStringContainsString(
            $this->dateRangeLabel($previousFrom, $previousTo).':'.$previous,
            $this->normalizedText($crawler->filter('[data-dashboard-kpi-comparison="inflow30"]')),
        );
        $periodNodes = $crawler->filter('[data-dashboard-kpi-period="current"]');
        self::assertCount(3, $periodNodes);
        foreach ($periodNodes as $periodNode) {
            self::assertStringContainsString(
                $this->dateRangeLabel($currentFrom, $today),
                $periodNode->textContent,
            );
        }
        $balanceComparisonDate = 'На '.$previousTo->format(
            $previousTo->format('Y') === $today->format('Y') ? 'd.m' : 'd.m.Y',
        );
        self::assertStringContainsString(
            str_replace(' ', '', $balanceComparisonDate).':1000RUB',
            $this->normalizedText($crawler->filter('[data-dashboard-kpi-comparison="todayBalance"]')),
        );

        $this->assertReconciliationLinks($crawler, $uiMode, 'operating', $periodDays);
    }

    private function assertReconciliationLinks(
        Crawler $crawler,
        string $uiMode,
        string $activity,
        int $periodDays = 30,
    ): void {
        $links = $crawler->filter('[data-dashboard-reconcile-link]');
        self::assertCount(3, $links);
        self::assertSame(['inflow30', 'outflow30', 'netFlow30'], $links->each(
            static fn (Crawler $link): string => (string) $link->attr('data-dashboard-reconcile-link'),
        ));
        self::assertSame(['Сверить в ДДС', 'Сверить в ДДС', 'Сверить в ДДС'], $links->each(
            static fn (Crawler $link): string => trim($link->text()),
        ));

        $cardSelector = UiModeResolver::LEGACY === $uiMode ? '.card' : 'article.kpi';
        $balanceCard = $crawler->filter('[data-dashboard-kpi="todayBalance"]')->closest($cardSelector);
        self::assertNotNull($balanceCard);
        self::assertCount(0, $balanceCard->filter('[data-dashboard-reconcile-link]'));

        $today = new \DateTimeImmutable('today');
        $expectedQuery = [
            'from' => $today->modify(sprintf('-%d days', $periodDays - 1))->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
            'group' => 'month',
            'reconcile' => 'dashboard',
            'activity' => $activity,
            'currency' => 'RUB',
        ];
        $hrefs = $links->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertCount(1, array_unique($hrefs));

        $url = parse_url($hrefs[0]);
        self::assertSame('/finance/reports/cashflow', $url['path'] ?? null);
        parse_str($url['query'] ?? '', $query);
        self::assertSame($expectedQuery, $query);
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
