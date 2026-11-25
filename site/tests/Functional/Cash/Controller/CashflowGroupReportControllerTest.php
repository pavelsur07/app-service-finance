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
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

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

        $crawler = $this->client->request('GET', '/finance/reports/cashflow/group?from=2026-11-01&to=2026-11-30');

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

        $this->client->request('GET', '/finance/reports/cashflow/group/export.xlsx?from=2026-11-01&to=2026-11-30');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('2026-11-01_2026-11-30.xlsx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
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
        $page = $this->client->request('GET', '/finance/reports/cashflow/group'.$period);
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
    }

    public function testCompanyOutsideGroupExplains(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::ALONE);

        $crawler = $this->client->request('GET', '/finance/reports/cashflow/group');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('не входит в группу', $crawler->filter('main, body')->first()->text());
        self::assertSelectorNotExists('[data-test="group-report"]');
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
    }
}
