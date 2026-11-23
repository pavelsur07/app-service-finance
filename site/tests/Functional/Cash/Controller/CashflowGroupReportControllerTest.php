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
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Консолидированный отчёт ДДС группы (cashflow-group-chart, этап 4). */
final class CashflowGroupReportControllerTest extends WebTestCaseBase
{
    private const HEAD = '0193eeee-0000-7000-8000-0000000004a1';
    private const MEMBER = '0193eeee-0000-7000-8000-0000000004a2';
    private const ALONE = '0193eeee-0000-7000-8000-0000000004a3';

    private KernelBrowser $client;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
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
        self::assertStringContainsString('Внутригрупповые', $head);
        self::assertStringContainsString('Поступления от покупателей', $crawler->filter('[data-test="group-report"] tbody')->text());
        self::assertStringContainsString('Не сопоставлено', $crawler->filter('[data-test="group-report"]')->text());
        self::assertStringContainsString('ИНН', $crawler->filter('[data-test="group-report-inn-warning"]')->text(), 'у компаний теста нет ИНН');

        $this->client->request('GET', '/finance/reports/cashflow/group/export.xlsx?from=2026-11-01&to=2026-11-30');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('2026-11-01_2026-11-30.xlsx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
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
