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
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Раздел «Бюджет ОПиУ»: список вариантов, создание, смена статуса, страница варианта, API сетки.
 */
final class BudgetPagesTest extends WebTestCaseBase
{
    use ClockSensitiveTrait;

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
        $active = $crawler->filter('[data-budget-versions] tbody')->first()->text();
        self::assertLessThan(strpos($active, 'Черновик А'), strpos($active, 'Утверждённый Б'));
        self::assertStringNotContainsString('Архивный В', $active);
        self::assertSelectorTextContains('[data-budget-archive]', 'Архив (1)');
        self::assertSelectorTextContains('#budget-archive-rows', 'Архивный В');
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Прошлый год Г', $page);
        self::assertStringNotContainsString('Чужой Д', $page);
        self::assertSelectorTextContains('[data-budget-versions] .vf-menu-item--reason', 'Роль «Чтение»: изменять варианты нельзя', 'действия у читателя видны неактивными с причиной');
        self::assertSelectorTextContains('#budget-new-reason', 'Роль «Чтение»');
        self::assertSelectorTextContains('.vf-page-head h1', 'Бюджет ОПиУ');
        self::assertSelectorExists('#navbar-pl a.dropdown-item.active[href="/finance/budget"]');
    }

    public function testListShowsYearNetProfitOnlyWhenTreeHasNetProfitRow(): void
    {
        $id = $this->version('Базовый', BudgetVersionStatus::DRAFT);
        $this->line($id, '2027-01-01', '1250000.00');
        $this->login($this->reader);

        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-budget-year-total]');

        $this->em()->getConnection()->executeStatement("UPDATE pl_categories SET code = 'NET_PROFIT' WHERE company_id = ? AND code = 'PROFIT'", [self::COMPANY_ID]);
        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertSelectorTextContains('[data-budget-versions] th[data-budget-year-total]', 'Чистая прибыль, ₽');
        self::assertSelectorTextContains('[data-budget-versions] td[data-budget-year-total]', "1\u{00A0}250\u{00A0}000");
    }

    public function testPlanFactPageDefaultsToApprovedVersionAndShowsDeviation(): void
    {
        self::mockTime(new \DateTimeImmutable('2027-03-15 12:00', new \DateTimeZone('Europe/Moscow')));
        $draft = $this->version('Черновик', BudgetVersionStatus::DRAFT);
        $approved = $this->version('Утверждённый', BudgetVersionStatus::APPROVED);
        $this->line($approved, '2027-01-01', '1000.00');
        $this->line($draft, '2027-01-01', '5000.00');
        $this->login($this->reader);

        $this->client->request('GET', '/finance/budget/plan-fact?year=2027');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-header-tabs .active', 'План–факт');
        self::assertSelectorTextContains('select[name=version] option[selected]', 'Утверждённый');
        self::assertSelectorNotExists('[data-budget-no-approved]');
        // Январь закрыт: факта нет, отклонение −1 000 — хуже плана.
        self::assertSelectorTextContains('[data-pf-row="REV"] [data-pf-cell="2027-01"]', "\u{2212}1\u{00A0}000");
        self::assertSelectorTextContains('[data-pf-row="REV"] [data-pf-cell="2027-01"]', 'хуже плана');
        // Март идёт, апрель впереди — отклонения нет.
        self::assertSelectorTextSame('[data-pf-row="REV"] [data-pf-cell="2027-04"]', '—');
        self::assertSelectorTextContains('[data-pf-row="REV"] [data-pf-ytd="plan"]', "1\u{00A0}000");

        $this->client->request('GET', sprintf('/finance/budget/plan-fact?year=2027&version=%s&show=plan&period=quarter', $draft));
        self::assertSelectorTextContains('select[name=version] option[selected]', 'Черновик');
        self::assertSelectorTextContains('[data-pf-row="REV"] [data-pf-cell="2027-Q1"]', "5\u{00A0}000");
        self::assertSelectorNotExists('[data-pf-cell="2027-01"]');

        // Период запоминается в сессии — месяцы задаём явно.
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027&show=fact&period=month');
        $link = $this->client->getCrawler()->filter('[data-pf-row="REV"] [data-pf-cell="2027-01"] a');
        self::assertCount(1, $link);
        self::assertStringContainsString('/finance/report/preview?from=2027-01-01&to=2027-01-31', (string) $link->attr('href'));
    }

    public function testPlanFactFactLinksToReportOnlyWhenSumsMatchReport(): void
    {
        self::mockTime(new \DateTimeImmutable('2027-03-15 12:00', new \DateTimeZone('Europe/Moscow')));
        $this->version('Утверждённый', BudgetVersionStatus::APPROVED);
        $project = $this->em()->find(ProjectDirection::class, self::PROJECT_ID);
        self::assertNotNull($project);
        $sales = new FinancialResponsibilityCenter(self::COMPANY_ID, 'CFO_SALES', 'Продажи');
        $this->em()->persist($sales);
        $this->em()->persist(new FinancialResponsibilityCenterProject(self::COMPANY_ID, $project, $sales));
        $this->em()->flush();
        $salesId = $sales->getId();
        $this->em()->clear();
        $this->login($this->reader);
        $cell = '[data-pf-row="REV"] [data-pf-cell="2027-01"] a';

        // Системный «Общий» включает факт без ЦФО — отчёт с фильтром по ЦФО его не покажет.
        $this->client->request('GET', sprintf('/finance/budget/plan-fact?year=2027&show=fact&project=%s&center=%s', self::PROJECT_ID, $this->centerId));
        self::assertSelectorNotExists($cell);
        self::assertSelectorTextContains('.card-footer', 'операции без ЦФО');

        $url = sprintf('/finance/budget/plan-fact?year=2027&show=fact&project=%s&center=%s', self::PROJECT_ID, $salesId);
        $this->client->request('GET', $url);
        $href = (string) $this->client->getCrawler()->filter($cell)->attr('href');
        self::assertStringContainsString('responsibilityCenterIds', urldecode($href));

        $company = $this->em()->find(Company::class, self::COMPANY_ID);
        $parent = $this->em()->find(ProjectDirection::class, self::PROJECT_ID);
        self::assertNotNull($company);
        self::assertNotNull($parent);
        $child = new ProjectDirection(Uuid::uuid7()->toString(), $company, 'Магазин');
        $child->setParent($parent);
        $this->em()->persist($child);
        $this->em()->flush();
        $this->em()->clear();

        $this->client->request('GET', $url);
        self::assertSelectorNotExists($cell);
        self::assertSelectorTextContains('.card-footer', 'без дочерних');
    }

    public function testPlanFactPageWithoutApprovedHintsAndWithoutVersionsIsEmpty(): void
    {
        $this->version('Черновик', BudgetVersionStatus::DRAFT);
        $this->login($this->reader);

        $this->client->request('GET', '/finance/budget/plan-fact?year=2027');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-budget-no-approved]', 'Утвердите вариант или выберите черновик');
        self::assertSelectorTextContains('select[name=version] option[selected]', 'Черновик');

        $this->client->request('GET', '/finance/budget/plan-fact?year=2030');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-budget-plan-fact-empty]', 'На 2030 год вариантов нет');
        self::assertSelectorNotExists('[data-pf-row]');

        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertSelectorExists(sprintf('.card-header-tabs a[href="%s"]', '/finance/budget/plan-fact?year=2027'));
    }

    public function testPlanFactAllIndicatorsByColumnsAndByRows(): void
    {
        self::mockTime(new \DateTimeImmutable('2027-03-15 12:00', new \DateTimeZone('Europe/Moscow')));
        $approved = $this->version('Утверждённый', BudgetVersionStatus::APPROVED);
        $this->line($approved, '2027-01-01', '1000.00');
        $this->line($approved, '2027-02-01', '1000.00');
        $project = $this->em()->getReference(ProjectDirection::class, self::PROJECT_ID);
        self::assertNotNull($project);
        $company = $this->em()->getReference(Company::class, self::COMPANY_ID);
        self::assertNotNull($company);
        $total = new PLDailyTotal(Uuid::uuid7()->toString(), $company, $project, new \DateTimeImmutable('2027-02-10'), $this->em()->getReference(PLCategory::class, self::REVENUE_ID));
        $total->setAmountIncome('1050.00');
        $total->setResponsibilityCenterId($this->centerId);
        $this->em()->persist($total);
        $this->em()->flush();
        $this->login($this->reader);

        // Колонками: над каждым месяцем — План · Факт · Откл.; «Итого» по закрытым месяцам в конце.
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027&show=all&layout=columns&period=month');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-pf-mode="all-columns"]');
        self::assertSelectorTextContains('[data-pf-show] a.active', 'Все показатели');
        self::assertSelectorTextContains('[data-pf-layout] a.active', 'Колонками');
        self::assertCount(12 * 3 + 4, $this->client->getCrawler()->filter('.vf-pf__table thead tr')->eq(1)->filter('th'));
        self::assertSelectorTextContains('[data-pf-total-head]', 'Итого (янв–фев)');
        $cell = '[data-pf-row="REV"] td[data-pf-cell="2027-01"][data-pf-cell-metric="%s"]';
        self::assertSelectorTextContains(sprintf($cell, 'plan'), "1\u{00A0}000");
        // Факта нет: отклонение −100 % — значимое, подсвечено и помечено «хуже плана».
        self::assertSelectorTextContains(sprintf($cell, 'diff'), 'хуже плана');
        self::assertSelectorExists(sprintf($cell, 'diff').'.vf-pf__hl');
        self::assertSelectorNotExists(sprintf($cell, 'plan').'.vf-pf__hl');
        // Февраль: факт 1 050 при плане 1 000 — «+50», лучше плана, 5 % — без подсветки.
        $february = '[data-pf-row="REV"] td[data-pf-cell="2027-02"][data-pf-cell-metric="diff"]';
        self::assertSelectorTextContains($february, '+50');
        self::assertSelectorTextContains($february, 'лучше плана');
        self::assertSelectorNotExists($february.'.vf-pf__hl');

        // Кварталы колонками — ещё и %.
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027&show=all&layout=columns&period=quarter');
        self::assertCount(4 * 4 + 4, $this->client->getCrawler()->filter('.vf-pf__table thead tr')->eq(1)->filter('th'));

        // Строками: три строки на статью; «Итого» — каждая строка в своей колонке.
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027&show=all&layout=rows&period=month');
        self::assertSelectorExists('[data-pf-mode="all-rows"]');
        $rows = $this->client->getCrawler()->filter('[data-pf-row="REV"] tr');
        self::assertSame(['plan', 'fact', 'diff'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-pf-metric')));
        // Итого янв–фев: план 2 000, факт 1 050 → −950, −47,5 %.
        self::assertStringContainsString("2\u{00A0}000", $rows->eq(0)->filter('[data-pf-ytd="plan"]')->text());
        self::assertSame('', trim($rows->eq(1)->filter('[data-pf-ytd="plan"]')->text()));
        self::assertStringContainsString('хуже плана', $rows->eq(2)->filter('[data-pf-ytd="diff"]')->text());
        self::assertStringContainsString("\u{2212}47,5", $rows->eq(2)->filter('[data-pf-ytd="percent"]')->text());
    }

    public function testPlanFactRemembersViewAndDefaultsLayoutByPeriod(): void
    {
        self::mockTime(new \DateTimeImmutable('2027-03-15 12:00', new \DateTimeZone('Europe/Moscow')));
        $this->version('Утверждённый', BudgetVersionStatus::APPROVED);
        $this->login($this->reader);

        // Раскладка не выбрана: кварталы — колонками, месяцы — строками.
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027&show=all&period=quarter');
        self::assertSelectorExists('[data-pf-mode="all-columns"]');
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027');
        self::assertSelectorExists('[data-pf-mode="all-columns"]');
        self::assertSelectorTextContains('[aria-label="Период"] a.active', 'Кварталы');
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027&period=month');
        self::assertSelectorExists('[data-pf-mode="all-rows"]');

        // Выбранная раскладка помнится и при смене периода.
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027&layout=columns');
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027&period=month');
        self::assertSelectorExists('[data-pf-mode="all-columns"]');

        // Неизвестное значение не запоминается: показатель по умолчанию — отклонение.
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027&show=diff');
        $this->client->request('GET', '/finance/budget/plan-fact?year=2027&show=bogus');
        self::assertSelectorExists('[data-pf-mode="diff"]');
        self::assertSelectorNotExists('[data-pf-layout]');
    }

    public function testPlanFactPageForeignVersionIsNotFound(): void
    {
        $foreign = $this->version('Чужой', BudgetVersionStatus::DRAFT, companyId: self::OTHER_COMPANY_ID);
        $this->login($this->reader);

        $this->client->request('GET', sprintf('/finance/budget/plan-fact?year=2027&version=%s', $foreign));
        self::assertResponseStatusCodeSame(404);
    }

    public function testFillPreviewDoesNotWriteApplyNeedsOverwriteConfirmation(): void
    {
        $id = $this->version('Черновик', BudgetVersionStatus::DRAFT);
        $this->line($id, '2027-01-01', '500.00');
        $this->login($this->writer);

        $body = ['helper' => 'distribute', 'project' => self::PROJECT_ID, 'center' => $this->centerId, 'categoryIds' => [self::REVENUE_ID], 'params' => ['amount' => '1200']];
        $this->postFill($id, $body);
        self::assertResponseIsSuccessful();
        $preview = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([12, 1, 0, false], [$preview['changed'], $preview['overwrite'], $preview['skipped'], $preview['applied']]);
        self::assertSame(['plCategoryId' => self::REVENUE_ID, 'month' => '2027-01', 'amount' => '100.00', 'previous' => '500.00'], $preview['cells'][0]);
        self::assertSame(1, $this->lineCount($id));

        $this->postFill($id, $body + ['apply' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('budget_fill_overwrite_unconfirmed', $this->errorCode());
        self::assertSame(1, $this->lineCount($id));

        $this->postFill($id, $body + ['apply' => true, 'confirmOverwrite' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(12, $this->lineCount($id));

        // Повтор: менять нечего, строк столько же.
        $this->postFill($id, $body + ['apply' => true, 'confirmOverwrite' => true]);
        $repeat = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([0, false], [$repeat['changed'], $repeat['applied']]);
        self::assertSame(12, $this->lineCount($id));
    }

    public function testFillGuardsAccessCsrfStatusAndInput(): void
    {
        $draft = $this->version('Черновик', BudgetVersionStatus::DRAFT);
        $approved = $this->version('Утверждённый', BudgetVersionStatus::APPROVED, 2028);
        $body = ['helper' => 'distribute', 'project' => self::PROJECT_ID, 'center' => $this->centerId, 'categoryIds' => [self::REVENUE_ID], 'params' => ['amount' => '1200']];

        $this->login($this->reader);
        $this->postFill($draft, $body);
        self::assertResponseStatusCodeSame(403);

        $this->login($this->writer);
        $this->postFill($draft, $body, 'bad-token');
        self::assertSame('invalid_csrf', $this->errorCode());
        $this->postFill($approved, $body);
        self::assertSame('budget_version_not_editable', $this->errorCode());
        $this->postFill($draft, ['helper' => 'magic'] + $body);
        self::assertSame('budget_invalid_input', $this->errorCode());
        $this->postFill($draft, ['project' => '', 'center' => ''] + $body);
        self::assertSame('budget_invalid_pair', $this->errorCode());
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
        self::assertSelectorExists('[data-budget-new-modal][data-budget-new-open]');
        self::assertSelectorTextContains('[data-budget-new-error]', 'Год бюджета');
    }

    public function testCreateWithBlankNameReopensFormWithErrorUnderField(): void
    {
        $this->login($this->writer);
        $this->client->request('POST', '/finance/budget/versions', ['_token' => $this->csrfToken($this->client, 'budget_version_create'), 'versionId' => Uuid::uuid7()->toString(), 'name' => '  ', 'year' => '2027', 'method' => 'empty']);

        self::assertResponseRedirects('/finance/budget?year=2027');
        $this->client->followRedirect();
        self::assertSelectorExists('[data-budget-new-modal][data-budget-new-open]');
        self::assertSelectorTextContains('[data-budget-new-name-error]', 'Укажите название');
        self::assertSelectorExists('#budget-new-name.is-invalid');
        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertSelectorNotExists('[data-budget-new-modal][data-budget-new-open]', 'ошибка показывается один раз');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM budget_versions WHERE company_id = ?', [self::COMPANY_ID]));
    }

    public function testEmptyYearOffersBlankAndFactAndCreatesFromFact(): void
    {
        $this->login($this->writer);
        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertSelectorTextContains('[data-budget-empty]', 'На 2027 год вариантов нет');
        self::assertSelectorExists('[data-budget-empty] button[data-budget-new="fact"][disabled]');
        self::assertSelectorTextContains('#budget-fact-reason', 'За 2026 год нет факта ОПиУ');

        $project = $this->em()->getReference(ProjectDirection::class, self::PROJECT_ID);
        self::assertNotNull($project);
        $total = new PLDailyTotal(Uuid::uuid7()->toString(), $this->company, $project, new \DateTimeImmutable('2026-04-10'), $this->em()->getReference(PLCategory::class, self::REVENUE_ID));
        $total->setAmountIncome('1500.00');
        $total->setResponsibilityCenterId($this->centerId);
        $this->em()->persist($total);
        $this->em()->flush();

        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertSelectorExists('[data-budget-empty] button[data-budget-new="fact"]:not([disabled])');
        $id = Uuid::uuid7()->toString();
        $this->client->request('POST', '/finance/budget/versions', ['_token' => $this->csrfToken($this->client, 'budget_version_create'), 'versionId' => $id, 'name' => 'Из факта', 'year' => '2027', 'method' => 'fact']);
        self::assertResponseRedirects('/finance/budget/versions/'.$id);
        self::assertSame('1500.00', $this->em()->getConnection()->fetchOne("SELECT amount FROM budget_lines WHERE version_id = ? AND month = '2027-04-01'", [$id]));
    }

    public function testRowMenuShowsOwnerOnlyActionsDisabledWithReasonForWriter(): void
    {
        $draft = $this->version('Черновик', BudgetVersionStatus::DRAFT);

        $this->login($this->writer);
        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertSelectorTextContains('.budget-menu .vf-menu-item--reason', 'Утверждает только владелец компании');
        self::assertSelectorExists('.budget-menu button[data-budget-copy="'.$draft.'"]');
        self::assertSelectorExists('.budget-menu button[data-confirm-action$="/status/archived"]');

        $this->login($this->owner);
        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertSelectorExists('.budget-menu button[data-confirm-action$="/status/approved"][data-confirm-title="Утвердить «Черновик»?"]');
        self::assertSelectorNotExists('.budget-menu .vf-menu-item--reason');
    }

    public function testFactOfOppositeSignInDifferentPairsStillOffersFillFromFact(): void
    {
        // Встречные суммы двух пар гасятся в сумме по компании, но заполнять есть что.
        $shop = new ProjectDirection(Uuid::uuid7()->toString(), $this->company, 'Магазин');
        $this->em()->persist($shop);
        $this->em()->persist(new FinancialResponsibilityCenterProject(self::COMPANY_ID, $shop, $this->em()->find(FinancialResponsibilityCenter::class, $this->centerId) ?? throw new \LogicException()));
        $category = $this->em()->getReference(PLCategory::class, self::REVENUE_ID);
        $general = $this->em()->getReference(ProjectDirection::class, self::PROJECT_ID);
        self::assertNotNull($general);
        foreach ([[$general, '100.00', '0'], [$shop, '0', '100.00']] as [$project, $income, $expense]) {
            $total = new PLDailyTotal(Uuid::uuid7()->toString(), $this->company, $project, new \DateTimeImmutable('2026-05-10'), $category);
            $total->setAmountIncome($income);
            $total->setAmountExpense($expense);
            $total->setResponsibilityCenterId($this->centerId);
            $this->em()->persist($total);
        }
        $this->em()->flush();
        $this->login($this->writer);

        $this->client->request('GET', '/finance/budget?year=2027');
        self::assertSelectorExists('[data-budget-empty] button[data-budget-new="fact"]:not([disabled])');
        $id = Uuid::uuid7()->toString();
        $this->client->request('POST', '/finance/budget/versions', ['_token' => $this->csrfToken($this->client, 'budget_version_create'), 'versionId' => $id, 'name' => 'Из факта', 'year' => '2027', 'method' => 'fact']);
        self::assertResponseRedirects('/finance/budget/versions/'.$id);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM budget_lines WHERE version_id = ?', [$id]));
    }

    public function testReaderSeesEmptyYearButtonsDisabledWithReason(): void
    {
        $this->login($this->reader);
        $this->client->request('GET', '/finance/budget?year=2027');

        self::assertSelectorExists('[data-budget-empty] button[data-budget-new="empty"][disabled]');
        self::assertSelectorTextContains('#budget-fact-reason', 'Роль «Чтение»');
        self::assertSelectorNotExists('#budget-new');
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
        self::assertSame([['projectDirectionId' => self::PROJECT_ID, 'responsibilityCenterId' => $this->centerId, 'label' => 'Общий × Общий', 'projectName' => 'Общий', 'centerName' => 'Общий', 'system' => true]], $props['pairs']);

        // Шапка черновика для записи без владения: карандаш, слоты острова, «Утвердить» неактивна с причиной текстом.
        self::assertSelectorExists('[data-budget-rename]');
        self::assertSelectorExists('[data-budget-rename-form][data-url="/finance/budget/versions/'.$id.'/name"]');
        self::assertSelectorExists('[data-budget-head-slot="save"]');
        self::assertSelectorExists('[data-budget-head-slot="helpers"]');
        self::assertSelectorTextContains('[data-budget-status-action][disabled]', 'Утвердить');
        self::assertSelectorTextContains('[data-budget-status-reason]', 'Утверждает только владелец компании');
        self::assertSelectorExists('a[data-budget-copy-link][href="/finance/budget?year=2027&copy='.$id.'"]');
        self::assertSelectorExists('[data-confirm-action$="/status/archived"]');
        self::assertSelectorExists('a[data-budget-export-link]');
        self::assertSelectorNotExists('[data-budget-banner]');
    }

    public function testVersionHeaderByStatusAndRole(): void
    {
        $approved = $this->version('Утверждённый', BudgetVersionStatus::APPROVED);
        $archived = $this->version('Старый', BudgetVersionStatus::ARCHIVED);
        $draft = $this->version('Черновик', BudgetVersionStatus::DRAFT);

        $this->login($this->writer);
        $this->client->request('GET', '/finance/budget/versions/'.$approved);
        self::assertSelectorTextContains('[data-budget-banner="approved"]', 'это может сделать владелец компании');
        self::assertSelectorTextContains('[data-budget-status-action][disabled]', 'Вернуть в черновик');
        self::assertSelectorTextContains('[data-budget-status-reason]', 'Возвращает в черновик только владелец компании');
        self::assertSelectorNotExists('[data-budget-rename]');
        self::assertSelectorNotExists('[data-budget-head-slot="helpers"]');
        self::assertSelectorNotExists('[data-confirm-action$="/status/archived"]');

        $this->client->request('GET', '/finance/budget/versions/'.$archived);
        self::assertSelectorTextContains('[data-budget-banner="archived"]', 'Восстановите, чтобы править');
        self::assertSelectorTextContains('[data-budget-status-action][data-confirm-action$="/status/draft"]', 'Восстановить');
        self::assertSelectorNotExists('[data-budget-status-reason]');

        $this->login($this->owner);
        $this->client->request('GET', '/finance/budget/versions/'.$approved);
        self::assertSelectorTextNotContains('[data-budget-banner="approved"]', 'владелец');
        self::assertSelectorExists('[data-budget-status-action][data-confirm-action$="/status/draft"]');

        $this->login($this->reader);
        $this->client->request('GET', '/finance/budget/versions/'.$draft);
        self::assertSelectorTextContains('[data-budget-banner="read-only"]', 'доступ только для просмотра');
        self::assertSelectorNotExists('[data-budget-rename]');
        self::assertSelectorTextContains('[data-budget-status-reason]', 'Роль «Чтение»');
        self::assertSelectorNotExists('[data-budget-copy-link]');
        self::assertSelectorTextContains('.budget-menu .vf-menu-item--reason', 'Копировать');
    }

    public function testCopyFromVersionMenuOpensNewVersionFormWithSource(): void
    {
        $source = $this->version('Базовый', BudgetVersionStatus::DRAFT);
        $this->version('Другой', BudgetVersionStatus::APPROVED);
        $this->login($this->writer);

        $this->client->request('GET', '/finance/budget?year=2027&copy='.$source);
        self::assertSelectorExists('#budget-new[data-budget-new-open]');
        self::assertSelectorExists('input[name="method"][value="copy"][checked]');
        self::assertSelectorExists('#budget-new-source option[value="'.$source.'"][selected]');

        $this->client->request('GET', '/finance/budget?year=2027&copy='.Uuid::uuid7()->toString());
        self::assertSelectorNotExists('#budget-new[data-budget-new-open]');
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
        self::mockTime(new \DateTimeImmutable('2028-02-01 12:00', new \DateTimeZone('Europe/Moscow')));
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

    /**
     * @param array<string, mixed> $body
     */
    private function postFill(string $versionId, array $body, ?string $token = null): void
    {
        $this->client->request(
            'POST',
            '/finance/budget/versions/'.$versionId.'/fill',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token ?? $this->csrfToken($this->client, 'budget_cells'),
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function lineCount(string $versionId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM budget_lines WHERE version_id = ?', [$versionId]);
    }

    private function line(string $versionId, string $month, string $amount): void
    {
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO budget_lines (id, company_id, version_id, pl_category_id, month, project_direction_id, responsibility_center_id, amount, source, comment, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', NULL, NOW())",
            [Uuid::uuid7()->toString(), self::COMPANY_ID, $versionId, self::REVENUE_ID, $month, self::PROJECT_ID, $this->centerId, $amount],
        );
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
