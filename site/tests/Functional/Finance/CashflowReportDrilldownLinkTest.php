<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Сумма статьи в отчёте ДДС ведёт в «Транзакции ДДС» с фильтрами ячейки,
 * и список по этой ссылке складывается ровно в цифру ячейки.
 */
final class CashflowReportDrilldownLinkTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private Company $company;
    private MoneyAccount $account;
    private CashflowCategory $parent;
    private CashflowCategory $child;
    private CashflowCategory $leaf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $em = $this->em();

        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('drilldown-report@example.test')->build();
        $this->company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->forCompany($this->company)->build();
        $em->persist($owner);
        $em->persist($this->company);
        $em->persist($this->account);

        $this->parent = $this->category('Parent');
        $this->child = $this->category('Child', $this->parent);
        $this->leaf = $this->category('Leaf');
        $em->flush();

        $this->client->loginUser($owner);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->company->getId());
    }

    public function testCellLinksCarryCategoryPeriodAndCurrency(): void
    {
        $this->tx($this->child, '2026-01-10', '100.00');
        $this->tx($this->child, '2026-01-20', '50.00');
        $this->tx($this->leaf, '2026-02-05', '30.00');
        $this->em()->flush();

        $crawler = $this->report(['from' => '2026-01-05', 'to' => '2026-02-20', 'group' => 'month']);

        // Родитель, январь: статья с поддеревом, начало колонки обрезано началом отчёта.
        $parentJan = $this->linkQuery($crawler, $this->parent, 0);
        self::assertSame((string) $this->parent->getId(), $parentJan['categoryId']);
        self::assertSame('1', $parentJan['includeChildren']);
        self::assertSame('2026-01-05', $parentJan['dateFrom']);
        self::assertSame('2026-01-31', $parentJan['dateTo']);
        self::assertSame('RUB', $parentJan['currency']);
        self::assertArrayNotHasKey('projectIds', $parentJan);
        self::assertArrayNotHasKey('centerIds', $parentJan);

        // Лист, февраль: без флага поддерева, конец колонки обрезан концом отчёта.
        $leafFeb = $this->linkQuery($crawler, $this->leaf, 1);
        self::assertSame((string) $this->leaf->getId(), $leafFeb['categoryId']);
        self::assertArrayNotHasKey('includeChildren', $leafFeb);
        self::assertSame('2026-02-01', $leafFeb['dateFrom']);
        self::assertSame('2026-02-20', $leafFeb['dateTo']);

        // Нулевая ячейка и сальдо — без ссылки.
        self::assertCount(0, $this->cell($crawler, $this->leaf, 0)->filter('a'));
        self::assertCount(0, $crawler->filter('[data-cashflow-report] tr.fw-bold a'));
    }

    public function testListBehindParentLinkAddsUpToCell(): void
    {
        $this->tx($this->child, '2026-01-10', '100.00', 'TX-CHILD-1');
        $this->tx($this->child, '2026-01-20', '50.00', 'TX-CHILD-2');
        $this->tx($this->parent, '2026-01-25', '7.00', 'TX-PARENT');
        $this->tx($this->leaf, '2026-01-11', '30.00', 'TX-LEAF');
        $this->tx($this->child, '2026-02-03', '999.00', 'TX-NEXT-MONTH');
        $this->em()->flush();

        $crawler = $this->report(['from' => '2026-01-01', 'to' => '2026-02-28', 'group' => 'month']);
        $href = (string) $this->cell($crawler, $this->parent, 0)->filter('a')->attr('href');

        $list = $this->client->request('GET', $href);
        self::assertResponseIsSuccessful();
        $text = $list->filter('table tbody')->text();
        self::assertStringContainsString('TX-CHILD-1', $text);
        self::assertStringContainsString('TX-CHILD-2', $text);
        self::assertStringContainsString('TX-PARENT', $text);
        self::assertStringNotContainsString('TX-LEAF', $text);
        self::assertStringNotContainsString('TX-NEXT-MONTH', $text);
        self::assertCount(3, $list->filter('.js-cash-transaction-select'));
    }

    public function testQuarterColumnUsesQuarterBounds(): void
    {
        $this->tx($this->leaf, '2026-05-10', '10.00');
        $this->em()->flush();

        $crawler = $this->report(['from' => '2026-01-01', 'to' => '2026-12-31', 'group' => 'quarter']);
        $q2 = $this->linkQuery($crawler, $this->leaf, 1);

        self::assertSame('2026-04-01', $q2['dateFrom']);
        self::assertSame('2026-06-30', $q2['dateTo']);
    }

    public function testActiveProjectFilterIsPassedWithExpandedChildren(): void
    {
        $projectA = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'A');
        $projectA1 = (new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'A1'))->setParent($projectA);
        $projectB = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'B');
        $em = $this->em();
        $em->persist($projectA);
        $em->persist($projectA1);
        $em->persist($projectB);
        $this->tx($this->leaf, '2026-01-10', '10.00', 'TX-A1')->setProjectDirection($projectA1);
        $this->tx($this->leaf, '2026-01-11', '20.00', 'TX-B')->setProjectDirection($projectB);
        $em->flush();

        $crawler = $this->report([
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'projectFiltersPresent' => '1',
            'projectDirectionIds' => [(string) $projectA->getId()],
        ]);
        $query = $this->linkQuery($crawler, $this->leaf, 0);
        $ids = explode(',', $query['projectIds']);
        sort($ids);
        $expected = [(string) $projectA->getId(), (string) $projectA1->getId()];
        sort($expected);
        self::assertSame($expected, $ids);

        $list = $this->client->request('GET', (string) $this->cell($crawler, $this->leaf, 0)->filter('a')->attr('href'));
        $text = $list->filter('table tbody')->text();
        self::assertStringContainsString('TX-A1', $text);
        self::assertStringNotContainsString('TX-B', $text);
    }

    public function testDashboardReconciliationModeHasNoLinks(): void
    {
        $this->tx($this->leaf, '2026-01-10', '10.00');
        $this->em()->flush();

        $crawler = $this->report([
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'reconcile' => 'dashboard',
            'activity' => 'all',
            'currency' => 'RUB',
        ]);

        self::assertCount(0, $crawler->filter('[data-cashflow-report] a'));
    }

    /** @param array<string, mixed> $query */
    private function report(array $query): Crawler
    {
        $crawler = $this->client->request('GET', '/finance/reports/cashflow', $query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function cell(Crawler $crawler, CashflowCategory $category, int $periodIndex): Crawler
    {
        return $crawler
            ->filter(sprintf('[data-cashflow-report] tr[data-id="%s"] td', $category->getId()))
            ->eq($periodIndex + 1);
    }

    /** @return array<string, string> */
    private function linkQuery(Crawler $crawler, CashflowCategory $category, int $periodIndex): array
    {
        $link = $this->cell($crawler, $category, $periodIndex)->filter('a');
        self::assertCount(1, $link, 'В ячейке ожидается ссылка на операции');
        $href = (string) $link->attr('href');
        self::assertStringStartsWith('/finance/cash-transactions/?', $href);
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        $params = [];
        foreach ($query as $key => $value) {
            self::assertIsString($value);
            $params[(string) $key] = $value;
        }

        return $params;
    }

    private function category(string $name, ?CashflowCategory $parent = null): CashflowCategory
    {
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($this->company)
            ->withName($name)
            ->build();
        $category->setParent($parent);
        $this->em()->persist($category);

        return $category;
    }

    private function tx(CashflowCategory $category, string $date, string $amount, string $description = 'TX'): CashTransaction
    {
        $tx = CashTransactionBuilder::aCashTransaction()
            ->forCompany($this->company)
            ->withMoneyAccount($this->account)
            ->withCashflowCategory($category)
            ->withAmount($amount)
            ->withOccurredAt(new \DateTimeImmutable($date))
            ->build();
        $tx->setDescription($description);
        $this->em()->persist($tx);

        return $tx;
    }
}
