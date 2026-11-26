<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Application\Category\ApplyCashflowCoreTemplateAction;
use App\Cash\Application\Category\EnsureCashflowSystemCategoriesAction;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\ConsolidationMembershipBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/** Консолидированный отчёт ДДС группы (cashflow-group-chart, этап 4). */
final class CashflowGroupReportControllerTest extends WebTestCaseBase
{
    use IntragroupReportFixture;

    private const HEAD = '0193eeee-0000-7000-8000-0000000004a1';
    private const MEMBER = '0193eeee-0000-7000-8000-0000000004a2';
    private const ALONE = '0193eeee-0000-7000-8000-0000000004a3';

    private KernelBrowser $client;
    private User $owner;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em()->getConnection()->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->owner = UserBuilder::aUser()->asCompanyOwner()->build();
        $this->em()->persist($this->owner);
        foreach ([self::HEAD => 'Головная', self::MEMBER => 'Дочерняя', self::ALONE => 'Одиночка'] as $id => $name) {
            $this->em()->persist(CompanyBuilder::aCompany()->withId($id)->withName($name)->withOwner($this->owner)->build());
        }
        $this->em()->persist(ConsolidationMembershipBuilder::aMembership()->forCompany(self::HEAD)->withHead(self::HEAD)->build());
        $this->em()->persist(ConsolidationMembershipBuilder::aMembership()->forCompany(self::MEMBER)->withHead(self::HEAD)->build());
        $this->em()->flush();
        foreach ([self::HEAD, self::MEMBER, self::ALONE] as $id) {
            self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class)($id);
        }
        self::getContainer()->get(ApplyCashflowCoreTemplateAction::class)(self::HEAD);
        $this->em()->flush();
        $this->em()->clear();
    }

    public function testOwnerSeesReportAndDownloadsXlsx(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);

        $crawler = $this->client->request('GET', '/finance/reports/cashflow/group?from=2026-11-01&to=2026-11-30&view=companies');

        self::assertResponseIsSuccessful();
        $head = $crawler->filter('[data-test="group-report"] thead')->text();
        self::assertStringContainsString('Головная', $head);
        self::assertStringContainsString('Дочерняя', $head);
        self::assertStringContainsString('Элиминация', $head);
        self::assertStringContainsString('Поступления от покупателей', $crawler->filter('[data-test="group-report"] tbody')->text());
        self::assertStringContainsString('Не сопоставлено', $crawler->filter('[data-test="group-report"]')->text());
        self::assertStringContainsString('ИНН', $crawler->filter('[data-test="group-report-inn-warning"]')->text(), 'у компаний теста нет ИНН');
        self::assertStringContainsString('Нет активных счетов', $crawler->filter('[data-test="group-report-accounts"]')->text());
        self::assertSame('/finance/reports/cashflow/group/reconciliation?from=2026-11-01&to=2026-11-30', $crawler->selectLink('Сверка')->attr('href'));

        $this->client->request('GET', '/finance/reports/cashflow/group/export.xlsx?from=2026-11-01&to=2026-11-30&view=companies');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('2026-11-01_2026-11-30.xlsx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAccountsAndCategoryBranchesStartCollapsedInBothViews(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);

        foreach (['periods', 'companies'] as $view) {
            $page = $this->client->request('GET', '/finance/reports/cashflow/group?view='.$view);
            self::assertResponseIsSuccessful();
            $accounts = $page->filter('details[data-test="group-report-accounts"]:not([open])');
            self::assertCount(1, $accounts);
            $summary = $accounts->filter('summary');
            self::assertStringContainsString('Данные по счетам', $summary->text());
            self::assertSame('нет счетов', $summary->filter('[data-accounts-count]')->text());
            self::assertCount(0, $summary->filter('[data-accounts-lagging]'));
            self::assertCount(1, $summary->filter('.vf-disclosure__chevron'));
            self::assertStringContainsString('Показать', $summary->text());
            $table = $page->filter('[data-cashflow-report]');
            self::assertCount(1, $table->filter('tr[data-row-key="sys:CF_OP"]:not([hidden]) button[aria-expanded="false"]'));
            self::assertCount(1, $table->filter('tr[data-row-key="OP_IN_CUSTOMERS"][hidden]'));
            self::assertCount(0, $table->filter('tr[data-row-key="OP_IN_CUSTOMERS"] button'));
            self::assertCount(1, $table->filter('tr[data-row-key="unmatched"]:not([hidden])'));
            self::assertCount(0, $table->filter('tfoot [hidden], tfoot button'));
        }
    }

    public function testDefaultPeriodsShowBalancesAndNovemberLinks(): void
    {
        $this->em = $this->em();
        $this->createGroupFixture();
        $this->loadPeriodTransactions();
        $member = $this->companies['Бета'];
        $owner = $member->getUser();
        self::assertInstanceOf(User::class, $owner);
        $this->client->loginUser($owner);
        $this->setClientSessionValue($this->client, 'active_company_id', $member->getId());

        $path = '/finance/reports/cashflow/group';
        $range = ['from' => '2026-10-01', 'to' => '2026-12-31'];
        $page = $this->client->request('GET', $path.'?'.http_build_query($range));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-test="group-report"]');
        $table = $page->filter('[data-test="group-period-report"]');
        self::assertCount(1, $table);
        self::assertSame(['10.2026', '11.2026', '12.2026', 'Итого за период'], array_slice($table->filter('thead th')->each(static fn (Crawler $th): string => $th->text()), 1));
        foreach ([
            'open' => ['Остаток на начало', '0,00', '10000,00', '2097283,03', '0,00'],
            'total' => ['Изменение', '10000,00', '2087283,03', '45000,00', '2142283,03'],
            'close' => ['Остаток на конец', '10000,00', '2097283,03', '2142283,03', '2142283,03'],
            'transit' => ['Деньги в пути', '0,00', '−40000,00', '40000,00', '0,00'],
            'unpaired' => ['Без пары', '0,00', '2750000,00', '0,00', '2750000,00'],
            'without-unpaired' => ['Итого без несопоставленных', '10000,00', '−662716,97', '45000,00', '−607716,97'],
        ] as $key => [$label, $october, $november, $december, $whole]) {
            $row = $table->filter('tfoot [data-row-key="'.$key.'"]');
            self::assertSame($label, $row->filter('th')->text());
            self::assertSame([$october, $november, $december, $whole], $row->filter('td .vf-amount')->each(
                static fn (Crawler $amount): string => str_replace([' ', "\u{00a0}", "\u{202f}", '+'], '', $amount->text())
            ));
        }
        $november = ['from' => '2026-11-01', 'to' => '2026-11-30'];
        $companies = $november + ['view' => 'companies'];
        self::assertReportLink($table->selectLink('11.2026'), $path, $companies);
        $article = $table->filter('tbody [data-row-key="OP_IN_OTHER"]');
        self::assertStringContainsString('⚠', $article->filter('th')->text());
        $drilldown = $article->filter('td')->eq(1)->filter('a');
        self::assertReportLink($drilldown, $path, $companies);
        $reconciliation = $path.'/reconciliation?'.http_build_query($november);
        self::assertSame($reconciliation.'#unpaired', $table->filter('[data-row-key="unpaired"] td')->eq(1)->filter('a')->attr('href'));
        self::assertSame($reconciliation.'#in-transit', $table->filter('[data-row-key="transit"] td')->eq(1)->filter('a')->attr('href'));
        self::assertReportLink($page->selectLink('Excel'), $path.'/export.xlsx', $range + ['view' => 'periods', 'group' => 'month']);
        self::assertReportLink($page->selectLink('Сверка'), $path.'/reconciliation', $range);
        self::assertCount(1, $page->selectLink('Отчёт ДДС компании'));
        $cross = $page->filterXPath('//a[contains(normalize-space(.), "Пары между периодами")]');
        self::assertStringContainsString('1,40000,00', str_replace([' ', "\u{00a0}", "\u{202f}"], '', $cross->text()));
        self::assertReportLink($cross, $path.'/reconciliation', $range);

        $this->client->click($drilldown->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="group-report"]');
    }

    public function testCompanyViewRestoresSelectedGroupingAndDates(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);
        $path = '/finance/reports/cashflow/group';
        $dates = ['from' => '2026-10-01', 'to' => '2026-12-31'];
        $page = $this->client->request('GET', $path.'?'.http_build_query($dates + ['view' => 'periods', 'group' => 'quarter']));
        $toCompanies = $page->selectLink('По компаниям');
        self::assertReportLink($toCompanies, $path, $dates + ['view' => 'companies', 'group' => 'quarter']);
        $companies = $this->client->click($toCompanies->link());
        self::assertResponseIsSuccessful();
        $toPeriods = $companies->selectLink('По периодам');
        self::assertReportLink($toPeriods, $path, $dates + ['view' => 'periods', 'group' => 'quarter']);
        $restored = $this->client->click($toPeriods->link());
        self::assertResponseIsSuccessful();
        self::assertSame('4 кв. 2026', $restored->filter('[data-test="group-period-report"] thead th')->eq(1)->text());
        self::assertCount(0, $restored->filterXPath('//a[contains(normalize-space(.), "Пары между периодами")]'));
    }

    public function testCoarsensQuarterToYearAndOffersExplicitYears(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);
        $path = '/finance/reports/cashflow/group?from=2017-01-01&to=2026-12-31&group=';
        foreach (['quarter', 'year'] as $group) {
            $page = $this->client->request('GET', $path.$group);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $page->filter('select[name="group"] option[value="year"]'));
            $headers = $page->filter('[data-test="group-period-report"] thead th');
            self::assertCount(12, $headers);
            self::assertSame('2017', $headers->eq(1)->text());
            self::assertSame('2026', $headers->eq(10)->text());
            self::assertCount('quarter' === $group ? 1 : 0, $page->filter('[data-test="group-period-coarsened"]'));
        }
    }

    public function testTwoYearsOfWeeksShowMonthlyHeadersAndExplainCoarsening(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);
        $page = $this->client->request('GET', '/finance/reports/cashflow/group?from=2025-01-01&to=2026-12-31&group=week');
        self::assertResponseIsSuccessful();
        $headers = $page->filter('[data-test="group-period-report"] thead th');
        self::assertCount(26, $headers);
        self::assertSame('01.2025', $headers->eq(1)->text());
        self::assertSame('12.2026', $headers->eq(24)->text());
        self::assertSelectorExists('[data-test="group-period-coarsened"]');
    }

    #[DataProvider('reportPaths')]
    public function testOversizedAnnualReportProvidesEditableHtml(string $path): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);
        $page = $this->client->request('GET', $path.'?from=1990-01-01&to=2026-12-31&group=year');
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('Диапазон слишком большой — выберите не больше 36 лет', $page->filter('body')->text());
        self::assertSelectorNotExists('[data-test="group-period-report"]');
        self::assertSelectorNotExists('[data-test="group-report"]');
        $form = $page->selectButton('Показать')->form(['from' => '1991-01-01']);
        self::assertSame('GET', $form->getMethod());
        self::assertSame('/finance/reports/cashflow/group', parse_url($form->getUri(), \PHP_URL_PATH));
        $corrected = $this->client->submit($form);
        self::assertResponseIsSuccessful();
        $headers = $corrected->filter('[data-test="group-period-report"] thead th');
        self::assertCount(38, $headers);
        self::assertSame('1991', $headers->eq(1)->text());
        self::assertSame('2026', $headers->eq(36)->text());
    }

    /** @return iterable<string, array{string}> */
    public static function reportPaths(): iterable
    {
        yield 'экран' => ['/finance/reports/cashflow/group'];
        yield 'прямой запрос XLSX' => ['/finance/reports/cashflow/group/export.xlsx'];
    }

    /** @param array<string, string> $parameters */
    private static function assertReportLink(Crawler $link, string $path, array $parameters): void
    {
        self::assertCount(1, $link);
        $uri = $link->link()->getUri();
        self::assertSame($path, parse_url($uri, \PHP_URL_PATH));
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
        foreach ($parameters as $key => $value) {
            self::assertSame($value, $query[$key] ?? null);
        }
    }

    public function testOwnerSeesReconciliationAmountsAndCanDrillIntoPairsForTheSelectedPeriod(): void
    {
        $this->em = $this->em();
        $this->createGroupFixture();
        $this->loadNovemberTransactions();
        $this->fillMissingInns();
        $this->addTransaction(34, '0002', '2026-11-30', '-10.00', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(35, '0003', '2026-12-02', '10.00', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        $head = $this->companies['Альфа'];
        $owner = $head->getUser();
        self::assertInstanceOf(User::class, $owner);
        $this->client->loginUser($owner);
        $this->setClientSessionValue($this->client, 'active_company_id', (string) $head->getId());

        $period = '?from=2026-11-01&to=2026-11-30';
        $reconciliation = '/finance/reports/cashflow/group/reconciliation'.$period;
        $page = $this->client->request('GET', '/finance/reports/cashflow/group'.$period.'&view=companies');
        self::assertResponseIsSuccessful();

        $income = $page->filter('[data-row-key="OP_IN_OTHER"]');
        self::assertStringContainsString('⚠', $income->text());
        self::assertSame($reconciliation.'&row=OP_IN_OTHER', $income->filter('a')->attr('href'));
        self::assertSame('−917000,00', str_replace(["\u{00a0}", ' '], '', $income->filter('a')->text()));
        $outflow = $page->filter('[data-row-key="OP_OUT_OTHER"]');
        self::assertStringNotContainsString('⚠', $outflow->text());
        self::assertSame('+917000,00', str_replace(["\u{00a0}", ' '], '', $outflow->filter('a')->text()));
        self::assertSame($reconciliation.'&row=total', $page->filter('[data-row-key="total"] a')->attr('href'));

        self::assertSame($reconciliation.'#in-transit', $page->filter('[data-test="group-report-transit"] a')->attr('href'));
        self::assertSame('−10,00', $page->filter('[data-test="group-report-transit"] .vf-amount')->text());
        self::assertSame('2750000,00', str_replace(["\u{00a0}", ' '], '', $page->filter('[data-test="group-report-unpaired"] .vf-amount')->text()));
        self::assertSame('−622726,97', str_replace(["\u{00a0}", ' '], '', $page->filter('[data-test="group-report-without-unpaired"] .vf-amount')->text()));
        $accounts = $page->filter('[data-test="group-report-accounts"]');
        self::assertStringContainsString('Альфа · ВТБ ****0001', $accounts->text());
        self::assertStringContainsString('12.11.2026', $accounts->text());
        self::assertStringContainsString('Отстаёт', $accounts->text());
        self::assertStringNotContainsString('40802810000000000001', $accounts->text());
        self::assertCount(1, $page->filter('details[data-test="group-report-accounts"][open]'), 'есть отстающий счёт — блок открыт');
        self::assertMatchesRegularExpression('/^\\d+ сч(ёт|ёта|етов)$/u', $accounts->filter('summary [data-accounts-count]')->text());
        self::assertMatchesRegularExpression('/^\\d+ отста(ёт|ют)$/u', $accounts->filter('summary [data-accounts-lagging]')->text());
    }

    public function testExportUsesSelectedViewAndDefaultsToCalendarMonths(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);
        $url = '/finance/reports/cashflow/group/export.xlsx?from=2026-10-01&to=2026-12-31';
        $path = tempnam(sys_get_temp_dir(), 'group_view_test_');
        self::assertNotFalse($path);
        try {
            foreach (['' => '10.2026', '&view=periods&group=quarter' => '4 кв. 2026', '&view=companies' => 'Элиминация'] as $query => $expectedHeader) {
                $this->client->request('GET', $url.$query);
                self::assertResponseIsSuccessful();
                file_put_contents($path, $this->client->getInternalResponse()->getContent());
                $book = (new Xlsx())->load($path);
                try {
                    $cells = array_merge(...$book->getSheet(0)->toArray());
                    self::assertContains($expectedHeader, $cells);
                } finally {
                    $book->disconnectWorksheets();
                }
            }
        } finally {
            unlink($path);
        }
    }

    public function testCompanyOutsideGroupExplains(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::ALONE);

        $crawler = $this->client->request('GET', '/finance/reports/cashflow/group');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('не входит в группу', $crawler->filter('main, body')->first()->text());
        self::assertSelectorNotExists('[data-test="group-report"]');
        self::assertSelectorNotExists('[data-test="group-period-report"]');
        $oversized = $this->client->request('GET', '/finance/reports/cashflow/group?from=1990-01-01&to=2026-12-31&group=year');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('не входит в группу', $oversized->filter('body')->text());
        self::assertStringNotContainsString('Диапазон слишком большой', $oversized->filter('body')->text());
    }

    public function testMemberWhoIsNotOwnerIsDenied(): void
    {
        $user = UserBuilder::aUser()->withIndex(541)->withRoles(['ROLE_COMPANY_USER'])->build();
        $company = $this->em()->find(Company::class, self::HEAD);
        \assert($company instanceof Company);
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Финансы', ['finance' => 'write'], $company);
        $member = CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($user)->withRole(CompanyMember::ROLE_OPERATOR)->withAccessRole($role)->build();
        foreach ([$user, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->client->loginUser($user);
        $this->setClientSessionValue($this->client, 'active_company_id', self::HEAD);

        $this->client->request('GET', '/finance/reports/cashflow/group');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/finance/reports/cashflow/group/export.xlsx');
        self::assertResponseStatusCodeSame(403);
        foreach (self::reportPaths() as [$path]) {
            $this->client->request('GET', $path.'?from=1990-01-01&to=2026-12-31&group=year');
            self::assertResponseStatusCodeSame(403);
        }
    }
}
