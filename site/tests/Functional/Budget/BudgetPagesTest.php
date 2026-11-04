<?php

declare(strict_types=1);

namespace App\Tests\Functional\Budget;

use App\Budget\Enum\BudgetVersionStatus;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Раздел «Бюджет ОПиУ»: список вариантов, создание, смена статуса, страница варианта, API сетки.
 */
final class BudgetPagesTest extends WebTestCaseBase
{
    private const COMPANY_ID = '0193eeee-0000-7000-8000-00000000c001';
    private const OTHER_COMPANY_ID = '0193eeee-0000-7000-8000-00000000c002';
    private const REVENUE_ID = '0193eeee-0000-7000-8000-00000000d002';
    private const PROJECT_ID = '0193eeee-0000-7000-8000-00000000e001';

    private KernelBrowser $client;
    private User $owner;
    private User $writer;
    private User $reader;
    private Company $company;
    private string $centerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-page-owner@example.test')->build();
        $this->writer = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-page-writer@example.test')->build();
        $this->reader = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-page-reader@example.test')->build();
        $this->company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($this->owner)->build();
        $writeRole = new CompanyRole(Uuid::uuid7()->toString(), 'Финансы — запись', ['finance' => 'write'], $this->company);
        $readRole = new CompanyRole(Uuid::uuid7()->toString(), 'Финансы — чтение', ['finance' => 'read'], $this->company);
        $subtotal = PLCategoryBuilder::aPLCategory()->forCompany($this->company)->withName('Прибыль')->withCode('PROFIT')->withType(PLCategoryType::SUBTOTAL)->build();
        $revenue = PLCategoryBuilder::aPLCategory()->withId(self::REVENUE_ID)->forCompany($this->company)->withName('Выручка')->withCode('REV')->withFlow(PLFlow::INCOME)->withParent($subtotal)->build();
        $project = new ProjectDirection(self::PROJECT_ID, $this->company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter(self::COMPANY_ID, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $this->centerId = $center->getId();

        $otherOwner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-page-other@example.test')->build();
        $otherCompany = CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($otherOwner)->build();

        foreach ([
            $this->owner, $this->writer, $this->reader, $this->company, $writeRole, $readRole,
            CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($this->company)->withUser($this->writer)->withAccessRole($writeRole)->build(),
            CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($this->company)->withUser($this->reader)->withAccessRole($readRole)->build(),
            $subtotal, $revenue, $project, $center,
            new FinancialResponsibilityCenterProject(self::COMPANY_ID, $project, $center),
            $otherOwner, $otherCompany,
        ] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
    }

    public function testListShowsVersionsOfYearApprovedFirstAndArchiveSeparately(): void
    {
        $this->version('Черновик А', BudgetVersionStatus::DRAFT);
        $this->version('Утверждённый Б', BudgetVersionStatus::APPROVED);
        $this->version('Архивный В', BudgetVersionStatus::ARCHIVED);
        $this->version('Прошлый год Г', BudgetVersionStatus::DRAFT, year: 2026);
        $this->version('Чужой Д', BudgetVersionStatus::DRAFT, companyId: self::OTHER_COMPANY_ID);

        $this->login($this->reader);
        $crawler = $this->client->request('GET', '/finance/budget?year=2027');

        self::assertResponseIsSuccessful();
        $active = $crawler->filter('[data-budget-versions]')->text();
        self::assertLessThan(strpos($active, 'Черновик А'), strpos($active, 'Утверждённый Б'));
        self::assertStringNotContainsString('Архивный В', $active);
        self::assertSelectorTextContains('[data-budget-archive]', 'Архивный В');
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Прошлый год Г', $page);
        self::assertStringNotContainsString('Чужой Д', $page);
        self::assertSelectorExists('[data-budget-versions] button[disabled]', 'действия у читателя видны неактивными');
        self::assertSelectorTextContains('.vf-page-head h1', 'Бюджет ОПиУ');
        self::assertSelectorExists('#navbar-pl a.dropdown-item.active[href="/finance/budget"]');
    }

    public function testListShowsYearNetProfitOnlyWhenTreeHasNetProfitRow(): void
    {
        $id = $this->version('Базовый', BudgetVersionStatus::DRAFT);
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO budget_lines (id, company_id, version_id, pl_category_id, month, project_direction_id, responsibility_center_id, amount, source, comment, updated_at)
             VALUES (?, ?, ?, ?, '2027-01-01', ?, ?, '1250000.00', 'manual', NULL, NOW())",
            [Uuid::uuid7()->toString(), self::COMPANY_ID, $id, self::REVENUE_ID, self::PROJECT_ID, $this->centerId],
        );
        $this->login($this->reader);

        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-budget-year-total]');

        $this->em()->getConnection()->executeStatement("UPDATE pl_categories SET code = 'NET_PROFIT' WHERE company_id = ? AND code = 'PROFIT'", [self::COMPANY_ID]);
        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertSelectorTextContains('[data-budget-versions] th[data-budget-year-total]', 'Итог года');
        self::assertSelectorTextContains('[data-budget-versions] td[data-budget-year-total]', "1\u{00A0}250\u{00A0}000");
    }

    public function testCreateEmptyVersionRedirectsAndRepeatKeepsOneRow(): void
    {
        $this->login($this->writer);
        $id = Uuid::uuid7()->toString();
        $form = ['_token' => $this->csrfToken($this->client, 'budget_version_create'), 'versionId' => $id, 'name' => 'Базовый', 'year' => '2027', 'copyFrom' => ''];

        $this->client->request('POST', '/finance/budget/versions', $form);
        self::assertResponseRedirects('/finance/budget/versions/'.$id);
        $this->client->request('POST', '/finance/budget/versions', $form);
        self::assertResponseRedirects('/finance/budget/versions/'.$id);

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM budget_versions WHERE company_id = ?', [self::COMPANY_ID]));
    }

    public function testCreateWithInvalidYearShowsMessageNot500(): void
    {
        $this->login($this->writer);
        $this->client->request('POST', '/finance/budget/versions', ['_token' => $this->csrfToken($this->client, 'budget_version_create'), 'versionId' => Uuid::uuid7()->toString(), 'name' => 'Базовый', 'year' => '1999', 'copyFrom' => '']);

        self::assertResponseRedirects('/finance/budget?year=1999');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'Год бюджета');
    }

    public function testReaderCannotCreateOrChangeStatus(): void
    {
        $id = $this->version('Черновик', BudgetVersionStatus::DRAFT);
        $this->login($this->reader);

        $this->client->request('POST', '/finance/budget/versions', ['_token' => $this->csrfToken($this->client, 'budget_version_create'), 'versionId' => Uuid::uuid7()->toString(), 'name' => 'X', 'year' => '2027']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/finance/budget/versions/'.$id.'/status/archived', ['_token' => $this->csrfToken($this->client, 'budget_version_status'.$id)]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerApprovesAndWriterGetsMessageInsteadOfApproval(): void
    {
        $id = $this->version('Черновик', BudgetVersionStatus::DRAFT);

        $this->login($this->writer);
        $this->client->request('POST', '/finance/budget/versions/'.$id.'/status/approved', ['_token' => $this->csrfToken($this->client, 'budget_version_status'.$id)]);
        self::assertResponseRedirects('/finance/budget/versions/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'только владелец');
        self::assertSame('draft', $this->statusOf($id));

        $this->login($this->owner);
        $this->client->request('POST', '/finance/budget/versions/'.$id.'/status/approved', ['_token' => $this->csrfToken($this->client, 'budget_version_status'.$id)]);
        self::assertResponseRedirects('/finance/budget/versions/'.$id);
        self::assertSame('approved', $this->statusOf($id));
    }

    public function testStatusChangeRequiresCsrf(): void
    {
        $id = $this->version('Черновик', BudgetVersionStatus::DRAFT);
        $this->login($this->owner);

        $this->client->request('POST', '/finance/budget/versions/'.$id.'/status/approved', ['_token' => 'wrong']);

        self::assertSame('draft', $this->statusOf($id));
    }

    public function testVersionPageMountsGridIslandWithProps(): void
    {
        $id = $this->version('Базовый', BudgetVersionStatus::DRAFT);
        $this->login($this->writer);

        $crawler = $this->client->request('GET', '/finance/budget/versions/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.vf-page-head h1', 'Базовый');
        $props = json_decode((string) $crawler->filter('[data-island="budget-grid"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($id, $props['versionId']);
        self::assertTrue($props['canWrite']);
        self::assertFalse($props['isOwner']);
        self::assertTrue($props['editable']);
        self::assertSame('/pl-categories/', $props['plCategoriesUrl']);
        self::assertSame([['projectDirectionId' => self::PROJECT_ID, 'responsibilityCenterId' => $this->centerId, 'label' => 'Общий × Общий', 'system' => true]], $props['pairs']);
    }

    public function testForeignVersionIsNotFoundEverywhere(): void
    {
        $id = $this->version('Чужой', BudgetVersionStatus::DRAFT, companyId: self::OTHER_COMPANY_ID);
        $this->login($this->owner);

        $this->client->request('GET', '/finance/budget/versions/'.$id);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/finance/budget/versions/'.$id.'/grid', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/finance/budget/versions/'.$id.'/status/archived', ['_token' => $this->csrfToken($this->client, 'budget_version_status'.$id)]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testGridJsonForPairAndInvalidPair(): void
    {
        $id = $this->version('Базовый', BudgetVersionStatus::DRAFT);
        $this->login($this->reader);

        $this->client->request('GET', sprintf('/finance/budget/versions/%s/grid?project=%s&center=%s', $id, self::PROJECT_ID, $this->centerId), server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $grid = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(2027, $grid['year']);
        self::assertCount(12, $grid['months']);
        self::assertSame(['PROFIT', 'REV'], array_column($grid['rows'], 'code'));
        self::assertSame('0.00', $grid['rows'][1]['values']['2027-01']);

        $this->client->request('GET', sprintf('/finance/budget/versions/%s/grid?project=%s&center=%s', $id, self::PROJECT_ID, Uuid::uuid7()->toString()), server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('budget_invalid_pair', $this->errorCode());
    }

    public function testPlanFactJsonForPairWholeCompanyAndInvalidPair(): void
    {
        $id = $this->version('Базовый', BudgetVersionStatus::DRAFT);
        $this->login($this->reader);

        $this->client->request('GET', sprintf('/finance/budget/versions/%s/plan-fact?project=%s&center=%s', $id, self::PROJECT_ID, $this->centerId));
        self::assertResponseIsSuccessful();
        $view = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(10, $view['thresholdPercent']);
        self::assertSame(['PROFIT', 'REV'], array_column($view['rows'], 'code'));
        self::assertSame(['plan' => '0.00', 'fact' => '0.00', 'diff' => '0.00', 'percent' => null, 'significant' => false], $view['rows'][1]['cells']['2027-01']);
        self::assertArrayHasKey('total', $view['rows'][1]['cells']);

        $this->client->request('GET', sprintf('/finance/budget/versions/%s/plan-fact', $id));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/finance/budget/versions/%s/plan-fact?project=%s&center=%s', $id, self::PROJECT_ID, Uuid::uuid7()->toString()));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('budget_invalid_pair', $this->errorCode());

        $this->client->request('GET', sprintf('/finance/budget/versions/%s/plan-fact', Uuid::uuid7()->toString()));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('budget_version_not_found', $this->errorCode());
    }

    public function testSaveCellsJson(): void
    {
        $id = $this->version('Базовый', BudgetVersionStatus::DRAFT);
        $this->login($this->writer);

        $this->postCells($id, [$this->cell('500000.00')]);
        self::assertResponseIsSuccessful();
        self::assertSame(['saved' => 1], json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame('500000.00', $this->em()->getConnection()->fetchOne('SELECT amount FROM budget_lines WHERE version_id = ?', [$id]));

        $this->postCells($id, [$this->cell('1 000,5')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('budget_invalid_input', $this->errorCode());

        $this->postCells($id, [$this->cell('1')], token: 'wrong');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf', $this->errorCode());
    }

    public function testCellsApiAnswersJsonErrorWithoutAcceptHeader(): void
    {
        $id = $this->version('Базовый', BudgetVersionStatus::DRAFT);
        $this->login($this->writer);

        $this->client->request('POST', '/finance/budget/versions/'.$id.'/cells', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client, 'budget_cells')], content: '{"cells": "x"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('budget_invalid_input', $this->errorCode());
    }

    public function testSaveCellsIntoApprovedVersionOrByReaderIsRejected(): void
    {
        $approved = $this->version('Утверждённый', BudgetVersionStatus::APPROVED);
        $this->login($this->writer);
        $this->postCells($approved, [$this->cell('1')]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('budget_version_not_editable', $this->errorCode());

        $draft = $this->version('Черновик', BudgetVersionStatus::DRAFT, year: 2028);
        $this->login($this->reader);
        $this->postCells($draft, [$this->cell('1')]);
        self::assertResponseStatusCodeSame(403);
    }

    private function version(string $name, BudgetVersionStatus $status, int $year = 2027, string $companyId = self::COMPANY_ID): string
    {
        $id = Uuid::uuid7()->toString();
        $this->em()->persist(BudgetVersionBuilder::aVersion()->withId($id)->forCompany($companyId)->withYear($year)->withName($name)->withStatus($status)->build());
        $this->em()->flush();
        $this->em()->clear();

        return $id;
    }

    private function login(User $user): void
    {
        $this->client->loginUser($user);
        $this->setClientSessionValue($this->client, 'active_company_id', self::COMPANY_ID);
    }

    private function statusOf(string $id): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT status FROM budget_versions WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, ?string>
     */
    private function cell(string $amount): array
    {
        return ['plCategoryId' => self::REVENUE_ID, 'month' => '2027-01', 'projectDirectionId' => self::PROJECT_ID, 'responsibilityCenterId' => $this->centerId, 'amount' => $amount, 'comment' => null];
    }

    /**
     * @param list<array<string, ?string>> $cells
     */
    private function postCells(string $versionId, array $cells, ?string $token = null): void
    {
        $this->client->request(
            'POST',
            '/finance/budget/versions/'.$versionId.'/cells',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token ?? $this->csrfToken($this->client, 'budget_cells'),
            ],
            content: json_encode(['cells' => $cells], \JSON_THROW_ON_ERROR),
        );
    }

    private function errorCode(): ?string
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($body) ? ($body['error']['code'] ?? null) : null;
    }
}
