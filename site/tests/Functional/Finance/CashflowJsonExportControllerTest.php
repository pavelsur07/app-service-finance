<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Service\ReportApiKeyManager;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CashflowJsonExportControllerTest extends WebTestCaseBase
{
    private const INDEX_URL = '/finance/reports/cashflow';
    private const EXPORT_URL = '/finance/reports/cashflow/export.json';

    public function testGuestIsRedirectedOrForbidden(): void
    {
        $client = static::createClient();

        $client->request('GET', self::EXPORT_URL);

        $statusCode = $client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [302, 403]);
        if (302 === $statusCode) {
            self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
        }
    }

    public function testAuthorizedUserGetsJsonAttachment(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a1');
        $this->seedCashflowData($company, '100.00', '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL.'?from=2026-04-01&to=2026-04-30&group=month');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^attachment; filename="cashflow-report-.*\.json"$/',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function testQueryParametersAreReflectedInPayloadAndAffectPeriods(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a2');
        $this->seedCashflowData($company, '150.00', '2026-04-02');
        $this->seedCashflowData($company, '250.00', '2026-04-09');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL.'?from=2026-04-01&to=2026-04-14&group=week');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertSame('week', $payload['group']);
        self::assertSame('week', $payload['filters']['group']);
        self::assertSame('2026-04-01', $payload['date_from']);
        self::assertSame('2026-04-01', $payload['filters']['date_from']);
        self::assertSame('2026-04-14', $payload['date_to']);
        self::assertSame('2026-04-14', $payload['filters']['date_to']);
        self::assertCount(2, $payload['periods']);
        self::assertSame('2026-04-01', $payload['periods'][0]['start']);
        self::assertSame('2026-04-07', $payload['periods'][0]['end']);
        self::assertSame('2026-04-08', $payload['periods'][1]['start']);
        self::assertSame('2026-04-14', $payload['periods'][1]['end']);
    }

    public function testPayloadContainsRequiredTopLevelKeys(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a3');
        $this->seedCashflowData($company, '100.00', '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL.'?from=2026-04-01&to=2026-04-30&group=month');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        foreach ([
            'company',
            'group',
            'date_from',
            'date_to',
            'periods',
            'openings',
            'closings',
            'tree',
            'categoryTree',
            'categoryTotals',
            'projectCenterMatrix',
        ] as $key) {
            self::assertArrayHasKey($key, $payload);
        }
    }

    public function testPayloadContainsProjectCenterMatrix(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a5');
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажа компьютеров');
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_KRD', 'Краснодар');
        $this->em()->persist($project);
        $this->em()->persist($center);
        $this->seedCashflowData($company, '100.00', '2026-04-15', $project, $center->getId());
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL.sprintf(
            '?from=2026-04-01&to=2026-04-30&group=month&responsibilityCenterId=%s',
            $center->getId(),
        ));

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertSame(['RUB'], $payload['projectCenterMatrix']['currencies']);
        self::assertSame('Продажа компьютеров', $payload['projectCenterMatrix']['rowsByProject'][0]['project_name']);
        self::assertSame($project->getId(), $payload['projectCenterMatrix']['rowsByProject'][0]['project_id']);
        self::assertSame($center->getId(), $payload['projectCenterMatrix']['rowsByProject'][0]['responsibility_center_id']);
        self::assertEquals([100.0], $payload['projectCenterMatrix']['rowsByProject'][0]['totals']['RUB']);
        self::assertSame($payload['projectCenterMatrix']['rowsByProject'], $payload['projectCenterMatrix']['rowsByCenter']);
    }

    public function testCashflowPageRendersProjectCenterMatrixBothWays(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a6');
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Сервисные услуги');
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_RND', 'Ростов');
        $this->em()->persist($project);
        $this->em()->persist($center);
        $this->seedCashflowData($company, '250.00', '2026-04-15', $project, $center->getId());
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $crawler = $client->request('GET', sprintf(
            '/finance/reports/cashflow?from=2026-04-01&to=2026-04-30&group=month&responsibilityCenterId=%s',
            $center->getId(),
        ));

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('Матрица Проект × ЦФО: RUB', $content);
        self::assertStringContainsString('ЦФО → проекты', $content);
        self::assertStringContainsString('Проект → ЦФО', $content);
        self::assertStringContainsString('Сервисные услуги', $content);
        self::assertStringContainsString('Ростов', $content);
        self::assertStringContainsString('+250,00', $content);
        self::assertSame(
            $center->getId(),
            $crawler->filter('input[data-pl-filter-name="responsibilityCenterIds[]"][checked]')->attr('value'),
        );
        self::assertSame(
            $center->getId(),
            $crawler->filter('input[data-pl-legacy-filter="responsibility-centers"]')->attr('value'),
        );
        self::assertCount(0, $crawler->filter('input[name="responsibilityCenterFiltersPresent"]:not([disabled])'));
        self::assertStringContainsString(
            'ЦФО: 1',
            $crawler->filter('.pl-preview-filters-row details')->eq(1)->filter('summary')->text(),
        );

        $exportQuery = $this->queryFromHref((string) $crawler
            ->filter('a[title="Скачать отчёт в формате JSON для проверки"]')
            ->attr('href'));
        self::assertSame($center->getId(), $exportQuery['responsibilityCenterId']);
        self::assertArrayNotHasKey('responsibilityCenterFiltersPresent', $exportQuery);
        self::assertArrayNotHasKey('responsibilityCenterIds', $exportQuery);
    }

    public function testCashflowPageUsesPreviewControlsAndPreservesFilterState(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a9');
        $projectA = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Project A');
        $projectB = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Project B');
        $centerA = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_A9', 'Center A');
        $centerB = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_B9', 'Center B');
        foreach ([$projectA, $projectB, $centerA, $centerB] as $entity) {
            $this->em()->persist($entity);
        }
        $this->seedCashflowData($company, '100.00', '2026-04-15', $projectA, $centerA->getId());
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);

        $crawler = $client->request('GET', self::INDEX_URL, [
            'from' => '2026-02-10',
            'to' => '2026-07-15',
            'group' => 'quarter',
            'projectFiltersPresent' => '1',
            'responsibilityCenterFiltersPresent' => '1',
            'projectDirectionIds' => [$projectA->getId()],
            'responsibilityCenterIds' => [$centerA->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Отчёт о движении денежных средств',
            trim($crawler->filter('.page-header h2.page-title')->text()),
        );
        $cashflowTables = $crawler->filter('table.cf-table');
        self::assertCount(1, $cashflowTables);
        self::assertSame(
            'Сальдо на начало',
            trim($cashflowTables->first()->filter('tbody tr')->first()->filter('td')->first()->text()),
        );
        // Шапка матрицы остаётся; запрещена только шапка карточки основной таблицы ДДС.
        self::assertCount(0, $crawler->filterXPath(
            '//div[contains(concat(" ", normalize-space(@class), " "), " card ")]'
            .'[.//table[contains(concat(" ", normalize-space(@class), " "), " cf-table ")]]'
            .'/div[contains(concat(" ", normalize-space(@class), " "), " card-header ")]',
        ));
        self::assertSame('Февраль – Июль 2026', trim($crawler->filter('.page-header .text-secondary')->text()));
        self::assertCount(1, $crawler->filter('.pl-preview-controls-card #cashflow-filter-form'));
        self::assertCount(1, $crawler->filter('[role="group"][aria-label="Режим отображения"]'));
        self::assertSame('2026-02-10', $crawler->filter('#cashflow-filter-form [data-pl-exact-from]')->attr('value'));
        self::assertSame('2026-07-15', $crawler->filter('#cashflow-filter-form [data-pl-exact-to]')->attr('value'));
        self::assertSame('quarter', $crawler->filter('#cashflow-filter-form [data-pl-group]')->attr('value'));
        self::assertSame('2026-02', $crawler->filter('#cashflow-filter-form [data-pl-month-from]')->attr('value'));
        self::assertSame('2026-07', $crawler->filter('#cashflow-filter-form [data-pl-month-to]')->attr('value'));
        self::assertCount(3, $crawler->filter('button[data-pl-set="group"]'));
        self::assertCount(1, $crawler->filter('button[data-pl-set="group"][data-pl-value="month"]'));
        self::assertCount(1, $crawler->filter('button[data-pl-set="group"][data-pl-value="quarter"].is-active'));
        self::assertCount(1, $crawler->filter('button[data-pl-set="group"][data-pl-value="year"]'));
        self::assertCount(0, $crawler->filter('button[data-pl-value="day"], button[data-pl-value="week"]'));
        self::assertCount(4, $crawler->filter('button[data-pl-period-from][data-pl-period-to]'));
        self::assertCount(1, $crawler->filter('input[name="projectFiltersPresent"][value="1"]'));
        self::assertCount(1, $crawler->filter('input[name="responsibilityCenterFiltersPresent"][value="1"]'));
        self::assertCount(1, $crawler->filter('input[name="projectDirectionIds[]"][checked]'));
        self::assertSame(
            $projectA->getId(),
            $crawler->filter('input[name="projectDirectionIds[]"][checked]')->attr('value'),
        );
        self::assertCount(1, $crawler->filter('input[name="responsibilityCenterIds[]"][checked]'));
        self::assertSame(
            $centerA->getId(),
            $crawler->filter('input[name="responsibilityCenterIds[]"][checked]')->attr('value'),
        );
        self::assertCount(0, $crawler->filter('select[name="responsibilityCenterId"]'));
        self::assertCount(1, $crawler->filter('.alert-info'));

        $jsonQuery = $this->queryFromHref((string) $crawler
            ->filter('a[title="Скачать отчёт в формате JSON для проверки"]')
            ->attr('href'));
        self::assertSame('2026-02-10', $jsonQuery['from']);
        self::assertSame('2026-07-15', $jsonQuery['to']);
        self::assertSame('quarter', $jsonQuery['group']);
        self::assertSame('1', $jsonQuery['projectFiltersPresent']);
        self::assertSame('1', $jsonQuery['responsibilityCenterFiltersPresent']);
        self::assertSame([$projectA->getId()], $jsonQuery['projectDirectionIds']);
        self::assertSame([$centerA->getId()], $jsonQuery['responsibilityCenterIds']);

        $resetQuery = $this->queryFromHref((string) $crawler->filter('a.pl-preview-reset')->attr('href'));
        self::assertSame('quarter', $resetQuery['group']);
        self::assertEqualsCanonicalizing(
            [$projectA->getId(), $projectB->getId()],
            $resetQuery['projectDirectionIds'],
        );
        self::assertEqualsCanonicalizing(
            [$centerA->getId(), $centerB->getId()],
            $resetQuery['responsibilityCenterIds'],
        );

        $noneSelected = $client->request('GET', self::INDEX_URL, [
            'projectFiltersPresent' => '1',
            'responsibilityCenterFiltersPresent' => '1',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Проекты: 0',
            $noneSelected->filter('.pl-preview-filters-row details')->eq(0)->filter('summary')->text(),
        );
        self::assertStringContainsString(
            'ЦФО: 0',
            $noneSelected->filter('.pl-preview-filters-row details')->eq(1)->filter('summary')->text(),
        );
        self::assertCount(0, $noneSelected->filter('input[name="projectDirectionIds[]"][checked]'));
        self::assertCount(0, $noneSelected->filter('input[name="responsibilityCenterIds[]"][checked]'));
        $noneSelectedJsonQuery = $this->queryFromHref((string) $noneSelected
            ->filter('a[title="Скачать отчёт в формате JSON для проверки"]')
            ->attr('href'));
        self::assertSame('1', $noneSelectedJsonQuery['projectFiltersPresent']);
        self::assertSame('1', $noneSelectedJsonQuery['responsibilityCenterFiltersPresent']);
        self::assertArrayNotHasKey('projectDirectionIds', $noneSelectedJsonQuery);
        self::assertArrayNotHasKey('responsibilityCenterIds', $noneSelectedJsonQuery);

        $legacy = $client->request('GET', self::INDEX_URL, [
            'from' => '2026-02-10',
            'to' => '2026-03-15',
            'group' => 'week',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('week', $legacy->filter('#cashflow-filter-form [data-pl-group]')->attr('value'));
        self::assertCount(0, $legacy->filter('button[data-pl-set="group"].is-active'));
        self::assertCount(3, $legacy->filter('button[data-pl-set="group"]'));
        $legacyJsonQuery = $this->queryFromHref((string) $legacy
            ->filter('a[title="Скачать отчёт в формате JSON для проверки"]')
            ->attr('href'));
        self::assertSame('week', $legacyJsonQuery['group']);
        self::assertEqualsCanonicalizing(
            [$projectA->getId(), $projectB->getId()],
            $legacyJsonQuery['projectDirectionIds'],
        );
        self::assertEqualsCanonicalizing(
            [$centerA->getId(), $centerB->getId()],
            $legacyJsonQuery['responsibilityCenterIds'],
        );
    }

    public function testCashflowControlsOmitDefaultMarkersForEmptyCatalogues(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('b9');
        $this->seedCashflowData($company, '100.00', '2026-04-15');
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);

        $crawler = $client->request('GET', self::INDEX_URL, [
            'from' => '2026-04-01',
            'to' => '2026-04-30',
            'group' => 'month',
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="projectFiltersPresent"]'));
        self::assertCount(0, $crawler->filter('input[name="responsibilityCenterFiltersPresent"]'));
        self::assertCount(0, $crawler->filter('input[name="projectDirectionIds[]"]'));
        self::assertCount(0, $crawler->filter('input[name="responsibilityCenterIds[]"]'));
        self::assertCount(2, $crawler->filter('.pl-preview-filter-menu-empty'));
        self::assertCount(0, $crawler->filter('a.pl-preview-reset'));
        self::assertCount(0, $crawler->filter('.alert-info'));

        $jsonQuery = $this->queryFromHref((string) $crawler
            ->filter('a[title="Скачать отчёт в формате JSON для проверки"]')
            ->attr('href'));
        self::assertArrayNotHasKey('projectFiltersPresent', $jsonQuery);
        self::assertArrayNotHasKey('responsibilityCenterFiltersPresent', $jsonQuery);
        self::assertArrayNotHasKey('projectDirectionIds', $jsonQuery);
        self::assertArrayNotHasKey('responsibilityCenterIds', $jsonQuery);
    }

    public function testExportIsScopedToCurrentUsersActiveCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$userA, $companyA] = $this->seedCompanyContext('a4');
        [, $companyB] = $this->seedCompanyContext('b4');
        $categoryA = $this->seedCashflowData($companyA, '100.00', '2026-04-15');
        $categoryB = $this->seedCashflowData($companyB, '900.00', '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $userA, $companyA);

        $client->request('GET', self::EXPORT_URL.'?from=2026-04-01&to=2026-04-30&group=month');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertSame($companyA->getId(), $payload['company']);
        self::assertArrayHasKey($categoryA->getId(), $payload['categoryTotals']);
        self::assertArrayNotHasKey($categoryB->getId(), $payload['categoryTotals']);
        self::assertEquals([100.0], $payload['categoryTotals'][$categoryA->getId()]['totals']['RUB']);
        self::assertEquals([100.0], $payload['closings']['RUB']);
    }

    public function testPluralFiltersUseProjectSubtreesAndKeepBalancesCompanyWide(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a7');
        $parent = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Consulting');
        $child = (new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Implementation'))->setParent($parent);
        $other = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Retail');
        $centerA = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_A', 'Center A');
        $centerB = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_B', 'Center B');
        foreach ([$parent, $child, $other, $centerA, $centerB] as $entity) {
            $this->em()->persist($entity);
        }

        $selectedCategory = $this->seedCashflowData($company, '100.00', '2026-04-10', $child, $centerA->getId());
        $otherProjectCategory = $this->seedCashflowData($company, '200.00', '2026-04-11', $other, $centerA->getId());
        $otherCenterCategory = $this->seedCashflowData($company, '300.00', '2026-04-12', $child, $centerB->getId());
        $unassignedCategory = $this->seedCashflowData($company, '400.00', '2026-04-13');
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL.'?'.http_build_query([
            'from' => '2026-04-01',
            'to' => '2026-04-30',
            'group' => 'month',
            'projectFiltersPresent' => 1,
            'projectDirectionIds' => [$parent->getId()],
            'responsibilityCenterFiltersPresent' => 1,
            'responsibilityCenterIds' => [$centerA->getId()],
        ]));

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertSame([$parent->getId()], $payload['filters']['project_direction_ids']);
        self::assertSame([$centerA->getId()], $payload['filters']['responsibility_center_ids']);
        self::assertSame($centerA->getId(), $payload['responsibility_center_id']);
        self::assertEquals([100.0], $payload['categoryTotals'][$selectedCategory->getId()]['totals']['RUB']);
        self::assertSame([], $payload['categoryTotals'][$otherProjectCategory->getId()]['totals']);
        self::assertSame([], $payload['categoryTotals'][$otherCenterCategory->getId()]['totals']);
        self::assertSame([], $payload['categoryTotals'][$unassignedCategory->getId()]['totals']);
        self::assertEquals([1000.0], $payload['closings']['RUB']);
        self::assertCount(1, $payload['projectCenterMatrix']['rowsByProject']);
        self::assertSame($child->getId(), $payload['projectCenterMatrix']['rowsByProject'][0]['project_id']);
        self::assertEquals([100.0], $payload['projectCenterMatrix']['rowsByProject'][0]['totals']['RUB']);
    }

    public function testPublicJsonAndCsvKeepLegacyRequestContracts(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [, $company] = $this->seedCompanyContext('a8');
        $category = $this->seedCashflowData($company, '125.00', '2026-04-15');
        $this->em()->flush();
        $token = self::getContainer()->get(ReportApiKeyManager::class)->createOrRegenerateForCompany($company);
        $query = http_build_query([
            'token' => $token,
            'from' => '2026-04-01',
            'to' => '2026-04-30',
            'group' => 'month',
        ]);

        $client->request('GET', '/api/public/reports/cashflow.json?'.$query);
        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);
        self::assertSame('month', $payload['group']);
        self::assertEquals([125.0], $payload['categoryTotals'][$category->getId()]['totals']['RUB']);
        self::assertArrayNotHasKey('project_direction_ids', $payload);
        self::assertArrayNotHasKey('responsibility_center_ids', $payload);

        $client->request('GET', '/api/public/reports/cashflow.csv?'.$query);
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertInstanceOf(StreamedResponse::class, $response);
        $csv = (string) $client->getInternalResponse()->getContent();
        $lines = preg_split('/\R/', trim($csv));
        self::assertSame('Период,КатегорияID,Валюта,"Сальдо нач.",Нетто,"Сальдо кон."', $lines[0]);
        self::assertStringContainsString((string) $category->getId(), $lines[1]);
    }

    /** @return array{0: User, 1: Company} */
    private function seedCompanyContext(string $suffix): array
    {
        $user = UserBuilder::aUser()
            ->withId(sprintf('22222222-2222-2222-2222-%s', str_pad($suffix, 12, '0', \STR_PAD_LEFT)))
            ->withEmail(sprintf('cashflow-export-%s@example.test', $suffix))
            ->asCompanyOwner()
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(sprintf('11111111-1111-1111-1111-%s', str_pad($suffix, 12, '0', \STR_PAD_LEFT)))
            ->withName(sprintf('Cashflow Export Company %s', $suffix))
            ->withOwner($user)
            ->build();

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);

        return [$user, $company];
    }

    private function seedCashflowData(
        Company $company,
        string $amount,
        string $occurredAt,
        ?ProjectDirection $project = null,
        ?string $responsibilityCenterId = null,
    ): CashflowCategory {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Operating inflow '.$occurredAt);

        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Main account '.substr($company->getId(), -2).' '.$occurredAt,
            'RUB',
        );
        $account->setOpeningBalance('0.00');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));

        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::INFLOW,
            $amount,
            'RUB',
            new \DateTimeImmutable($occurredAt),
        );
        $transaction->setCashflowCategory($category);
        $transaction->setProjectDirection($project);
        $transaction->setResponsibilityCenterId($responsibilityCenterId);

        // Зеркальная строка разбивки — то же, что делает синхронизатор на каждом пути
        // записи в бою; без неё отчёт, читающий строки, увидит пустоту.
        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $category, $amount, CashTransactionSplitSource::MANUAL),
        ]);

        $em = $this->em();
        $em->persist($category);
        $em->persist($account);
        $em->persist($transaction);

        return $category;
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /** @return array<string, mixed> */
    private function queryFromHref(string $href): array
    {
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return array<string, mixed> */
    private function decodeJson(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
