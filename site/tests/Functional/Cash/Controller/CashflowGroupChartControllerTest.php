<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Application\Category\ApplyCashflowCoreTemplateAction;
use App\Cash\Application\Category\EnsureCashflowSystemCategoriesAction;
use App\Cash\Entity\Transaction\CashflowCategory;
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

/** Экран «Эталон группы» у статей ДДС (cashflow-group-chart, этап 3). */
final class CashflowGroupChartControllerTest extends WebTestCaseBase
{
    private const HEAD = '0193eeee-0000-7000-8000-0000000003a1';
    private const MEMBER = '0193eeee-0000-7000-8000-0000000003a2';

    private KernelBrowser $client;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->owner = UserBuilder::aUser()->asCompanyOwner()->build();
        $this->em()->persist($this->owner);
        $head = CompanyBuilder::aCompany()->withId(self::HEAD)->withName('Головная')->withOwner($this->owner)->build();
        $member = CompanyBuilder::aCompany()->withId(self::MEMBER)->withName('Дочерняя')->withOwner($this->owner)->build();
        $this->em()->persist($head);
        $this->em()->persist($member);
        $this->em()->persist(ConsolidationMembershipBuilder::aMembership()->forCompany(self::HEAD)->withHead(self::HEAD)->build());
        $this->em()->persist(ConsolidationMembershipBuilder::aMembership()->forCompany(self::MEMBER)->withHead(self::HEAD)->build());
        $this->em()->flush();
        self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class)(self::HEAD);
        self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class)(self::MEMBER);
        self::getContainer()->get(ApplyCashflowCoreTemplateAction::class)(self::HEAD);
        $this->em()->flush();
        $this->em()->clear();
    }

    public function testMemberSeesHeadPlanAndAppliesIt(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);

        $crawler = $this->client->request('GET', '/cashflow-categories/group-chart');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Головная', $crawler->filter('[data-test="group-chart-source"]')->text());
        self::assertStringContainsString('OP_OUT_RENT', $crawler->filter('[data-test="group-chart-create"]')->text());

        $this->client->submit($crawler->selectButton('Применить план')->form());

        self::assertResponseRedirects('/cashflow-categories/group-chart');
        self::assertSame(37, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM cashflow_categories WHERE company_id = ? AND group_code IS NOT NULL', [self::MEMBER]));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('совпадает с эталоном', $crawler->filter('[data-test="group-chart-plan"]')->text());
    }

    public function testSyncedTreeWithUnmatchedIsNotReportedAsMatching(): void
    {
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);
        $crawler = $this->client->request('GET', '/cashflow-categories/group-chart');
        $this->client->submit($crawler->selectButton('Применить план')->form());
        $this->category(self::MEMBER, 'Своя статья');

        $plan = $this->client->request('GET', '/cashflow-categories/group-chart')->filter('[data-test="group-chart-plan"]')->text();

        self::assertStringNotContainsString('совпадает с эталоном', $plan);
        self::assertStringContainsString('Автоматических изменений нет', $plan);
    }

    public function testManualBindFromUnmatchedList(): void
    {
        $premises = $this->category(self::MEMBER, 'Помещение');
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);

        $crawler = $this->client->request('GET', '/cashflow-categories/group-chart');
        $row = $crawler->filter(sprintf('[data-test="group-chart-unmatched"] [data-category="%s"]', $premises));
        self::assertCount(1, $row);
        $form = $row->selectButton('Привязать')->form(['code' => 'OP_OUT_RENT']);
        $this->client->submit($form);

        self::assertResponseRedirects('/cashflow-categories/group-chart');
        self::assertSame('OP_OUT_RENT', $this->db()->fetchOne('SELECT group_code FROM cashflow_categories WHERE id = ?', [$premises]));

        // Повтор — отказ флешем, код не меняется.
        $this->client->request('POST', sprintf('/cashflow-categories/%s/group-code', $premises), ['code' => 'OP_OUT_SALARY', '_token' => $form->get('_token')->getValue()]);
        self::assertResponseRedirects('/cashflow-categories/group-chart');
        self::assertStringContainsString('уже есть код', $this->client->followRedirect()->filter('.toast.text-bg-danger')->text());
        self::assertSame('OP_OUT_RENT', $this->db()->fetchOne('SELECT group_code FROM cashflow_categories WHERE id = ?', [$premises]));
    }

    public function testCannotBindForeignCategory(): void
    {
        $foreign = $this->category(self::HEAD, 'Чужая');
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);
        $crawler = $this->client->request('GET', '/cashflow-categories/group-chart');
        $token = $crawler->filter('form[data-test="group-chart-apply"] input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf('/cashflow-categories/%s/group-code', $foreign), ['code' => 'OP_OUT_RENT', '_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->db()->fetchOne('SELECT group_code FROM cashflow_categories WHERE id = ?', [$foreign]));
    }

    public function testReadOnlyMemberSeesPlanButCannotChange(): void
    {
        $premises = $this->category(self::MEMBER, 'Помещение');
        $reader = UserBuilder::aUser()->withIndex(531)->withRoles(['ROLE_COMPANY_USER'])->build();
        $company = $this->em()->find(Company::class, self::MEMBER);
        \assert($company instanceof Company);
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Чтение', ['finance' => 'read'], $company);
        $member = CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($reader)->withRole(CompanyMember::ROLE_OPERATOR)->withAccessRole($role)->build();
        foreach ([$reader, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->client->loginUser($reader);
        $this->setClientSessionValue($this->client, 'active_company_id', self::MEMBER);

        $this->client->request('GET', '/cashflow-categories/group-chart');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="group-chart-create"]');
        self::assertSelectorNotExists('form[data-test="group-chart-apply"]');
        self::assertSelectorNotExists('[data-test="group-chart-unmatched"] form');
        $this->client->request('POST', '/cashflow-categories/group-chart/apply');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', sprintf('/cashflow-categories/%s/group-code', $premises), ['code' => 'OP_OUT_RENT']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM cashflow_categories WHERE company_id = ? AND group_code IS NOT NULL', [self::MEMBER]));
    }

    /** Несистемная статья без кода под «Операционной деятельностью». */
    private function category(string $companyId, string $name): string
    {
        $company = $this->em()->find(Company::class, $companyId);
        \assert($company instanceof Company);
        $parent = $this->em()->getRepository(CashflowCategory::class)->findOneBy(['company' => $company, 'systemCode' => CashflowCategory::CODE_OPERATING]);
        \assert($parent instanceof CashflowCategory);
        $category = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName($name)->setParent($parent);
        $category->syncFlowKindWithParent();
        $this->em()->persist($category);
        $this->em()->flush();
        $this->em()->clear();

        return (string) $category->getId();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }
}
