<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\PLValueFormat;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

final class PlReportPreviewControllerTest extends WebTestCaseBase
{
    public function testPreviewOpensAndSeedsPlStructure(): void
    {
        $client = static::createClient();
        $company = $this->loginWithCompany($client, 'pl-report-preview@example.test');

        $client->request('GET', '/finance/report/preview');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $this->em()->getRepository(PLCategory::class)->count(['company' => $company]),
        );
    }

    /**
     * Ширины колонок заданы через colgroup, поэтому рассинхрон colgroup и шапки ломает раскладку
     * молча: таблица просто перестаёт держать ширины. Проверяем оба layout и обе меты.
     */
    public function testColgroupMatchesHeaderColumns(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, 'pl-report-preview-colgroup@example.test');

        foreach ([
            '/finance/report/preview',
            '/finance/report/preview?show_meta=1',
            '/finance/report/preview?layout=projects',
            '/finance/report/preview?layout=projects&show_meta=1',
        ] as $url) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful($url);

            $cols = $crawler->filter('table.pl-table colgroup col')->count();
            $headers = $crawler->filter('table.pl-table thead th')->count();

            self::assertGreaterThan(0, $cols, $url);
            self::assertSame($headers, $cols, $url);
        }
    }

    /**
     * Согласованные ширины: день/месяц 160px, неделя 220px, проект 200px, итого 180px.
     */
    public function testGroupingUsesReadableDataColumnWidths(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, 'pl-report-preview-week@example.test');

        $dayStyle = $this->tableStyle($client, '/finance/report/preview?grouping=day');
        $weekStyle = $this->tableStyle($client, '/finance/report/preview?grouping=week');
        $monthStyle = $this->tableStyle($client, '/finance/report/preview?grouping=month');

        self::assertStringContainsString('--pl-col-period: 160px', $dayStyle);
        self::assertStringContainsString('--pl-col-period: 220px', $weekStyle);
        self::assertStringContainsString('--pl-col-period: 160px', $monthStyle);
        self::assertStringContainsString('min-width: calc(', $monthStyle);

        $client->request('GET', '/finance/report/preview?layout=projects');
        self::assertResponseIsSuccessful();

        $html = $client->getResponse()->getContent();
        self::assertIsString($html);
        self::assertStringContainsString('--pl-col-project: 200px', $html);
        self::assertStringContainsString('--pl-col-total: 180px', $html);
    }

    public function testNumericCellsExposeFullValueInTitle(): void
    {
        $client = static::createClient();
        $company = $this->loginWithCompany($client, 'pl-report-preview-title@example.test');
        $this->em()->persist(new ProjectDirection(
            '33333333-3333-3333-3333-000000000901',
            $company,
            'Тестовый проект',
        ));
        $this->em()->flush();

        foreach ([
            '/finance/report/preview',
            '/finance/report/preview?layout=projects',
        ] as $url) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful($url);

            $cells = $crawler->filter('table.pl-table tbody tr.pl-row td.text-end');
            self::assertGreaterThan(0, $cells->count(), $url);

            $cells->each(static function (Crawler $cell) use ($url): void {
                self::assertSame(trim($cell->text()), $cell->attr('title'), $url);
            });
        }
    }

    public function testTableUsesAccountingFormatOnlyForNegativeValues(): void
    {
        $client = static::createClient();
        $company = $this->loginWithCompany($client, 'pl-report-preview-accounting-format@example.test');
        $project = new ProjectDirection(
            '33333333-3333-3333-3333-000000000961',
            $company,
            'Project',
        );
        $negativeCategory = (new PLCategory('33333333-3333-3333-3333-000000000962', $company))
            ->setName('Negative row')
            ->setCode('NEGATIVE_ROW')
            ->setFlow(PLFlow::EXPENSE);
        $positiveCategory = (new PLCategory('33333333-3333-3333-3333-000000000963', $company))
            ->setName('Positive row')
            ->setCode('POSITIVE_ROW')
            ->setFlow(PLFlow::INCOME);
        $zeroCategory = (new PLCategory('33333333-3333-3333-3333-000000000964', $company))
            ->setName('Zero row')
            ->setCode('ZERO_ROW')
            ->setFlow(PLFlow::INCOME);
        $negativeTotal = (new PLDailyTotal(
            '33333333-3333-3333-3333-000000000965',
            $company,
            $project,
            new \DateTimeImmutable('2026-08-10'),
            $negativeCategory,
        ))->setAmountExpense('1234.50');
        $positiveTotal = (new PLDailyTotal(
            '33333333-3333-3333-3333-000000000966',
            $company,
            $project,
            new \DateTimeImmutable('2026-08-10'),
            $positiveCategory,
        ))->setAmountIncome('1234.50');

        foreach ([$project, $negativeCategory, $positiveCategory, $zeroCategory, $negativeTotal, $positiveTotal] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $query = 'grouping=month&from=2026-08-01&to=2026-08-31';
        foreach ([
            '/finance/report/preview?'.$query => 1,
            '/finance/report/preview?layout=projects&'.$query => 2,
        ] as $url => $expectedCellCount) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful($url);
            foreach ([
                (string) $negativeCategory->getId() => '(1 234.50)',
                (string) $positiveCategory->getId() => '1 234.50',
                (string) $zeroCategory->getId() => '0.00',
            ] as $rowId => $expected) {
                $cells = $crawler->filter(sprintf('tr.pl-row[data-row-id="%s"] td.text-end', $rowId));
                self::assertCount($expectedCellCount, $cells, $url);
                $cells->each(static function (Crawler $cell) use ($expected, $url): void {
                    self::assertSame($expected, trim($cell->text()), $url);
                    self::assertSame($expected, $cell->attr('title'), $url);
                });
            }
        }

        $client->request('GET', '/finance/report/preview/json?'.$query);
        self::assertResponseIsSuccessful();
        $payload = $this->responsePayload($client);
        $negativeRow = array_values(array_filter(
            $payload['rows'],
            static fn (array $row): bool => $negativeCategory->getId() === $row['id'],
        ));
        self::assertCount(1, $negativeRow);
        self::assertSame('-1 234.50', array_values($negativeRow[0]['values'])[0]);
    }

    public function testRowsWithChildrenAreBoldAndLeafRowsKeepRegularWeight(): void
    {
        $client = static::createClient();
        $company = $this->loginWithCompany($client, 'pl-report-preview-row-weight@example.test');
        $parent = (new PLCategory('33333333-3333-3333-3333-000000000951', $company))
            ->setName('Parent row')
            ->setCode('PARENT_ROW')
            ->setType(PLCategoryType::SUBTOTAL)
            ->setFlow(PLFlow::EXPENSE);
        $middle = (new PLCategory('33333333-3333-3333-3333-000000000952', $company))
            ->setName('Middle row')
            ->setCode('MIDDLE_ROW')
            ->setType(PLCategoryType::SUBTOTAL)
            ->setParent($parent)
            ->setFlow(PLFlow::EXPENSE);
        $leaf = (new PLCategory('33333333-3333-3333-3333-000000000953', $company))
            ->setName('Leaf row')
            ->setCode('LEAF_ROW')
            ->setParent($middle)
            ->setFlow(PLFlow::EXPENSE);
        $project = new ProjectDirection(
            '33333333-3333-3333-3333-000000000954',
            $company,
            'Project',
        );

        foreach ([$parent, $middle, $leaf, $project] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        foreach (['/finance/report/preview', '/finance/report/preview?layout=projects'] as $url) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful($url);
            $parentRow = $crawler->filter(sprintf('tr.pl-row[data-row-id="%s"]', $parent->getId()));
            $middleRow = $crawler->filter(sprintf('tr.pl-row[data-row-id="%s"]', $middle->getId()));
            $leafRow = $crawler->filter(sprintf('tr.pl-row[data-row-id="%s"]', $leaf->getId()));
            self::assertCount(1, $parentRow, $url);
            self::assertCount(1, $middleRow, $url);
            self::assertCount(1, $leafRow, $url);
            self::assertStringContainsString('fw-bold', (string) $parentRow->attr('class'), $url);
            self::assertStringContainsString('fw-bold', (string) $middleRow->attr('class'), $url);
            self::assertStringNotContainsString('fw-bold', (string) $leafRow->attr('class'), $url);
            self::assertCount(
                1,
                $parentRow->filter(sprintf(
                    'button.pl-name.pl-toggle[data-target="%s"][aria-expanded="true"]',
                    $parent->getId(),
                )),
                $url,
            );
            self::assertCount(
                1,
                $middleRow->filter(sprintf(
                    'button.pl-name.pl-toggle[data-target="%s"][aria-expanded="true"]',
                    $middle->getId(),
                )),
                $url,
            );
            self::assertCount(0, $leafRow->filter('.pl-toggle'), $url);
            self::assertCount(1, $leafRow->filter('span.pl-name'), $url);
            self::assertCount(0, $crawler->filter('.pl-name-wrap'), $url);
        }
    }

    public function testPreviewRendersHeaderAndFilterCardAndPreservesPluralStateInJsonAction(): void
    {
        $client = static::createClient();
        $company = $this->loginWithCompany($client, 'pl-report-preview-filter-card@example.test');
        $projectA = new ProjectDirection('33333333-3333-3333-3333-000000000931', $company, 'Project A');
        $projectB = new ProjectDirection('33333333-3333-3333-3333-000000000932', $company, 'Project B');
        $centerA = new FinancialResponsibilityCenter((string) $company->getId(), 'CENTER_A', 'Center A');
        $centerB = new FinancialResponsibilityCenter((string) $company->getId(), 'CENTER_B', 'Center B');

        foreach ([$projectA, $projectB, $centerA, $centerB] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $firstOfThisMonth = (new \DateTimeImmutable('today'))->modify('first day of this month');
        $crawler = $client->request('GET', '/finance/report/preview', [
            'from' => '2026-02-10',
            'to' => '2026-07-15',
            'grouping' => 'quarter',
            'layout' => 'periods',
            'show_meta' => '1',
            'projectFiltersPresent' => '1',
            'responsibilityCenterFiltersPresent' => '1',
            'projectDirectionIds' => [$projectA->getId()],
            'responsibilityCenterIds' => [$centerA->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Отчёт о прибылях и убытках', $crawler->filter('title')->text());
        $pageHeader = $crawler->filter('.page-header');
        self::assertSame('Отчёт о прибылях и убытках', trim($pageHeader->filter('h2.page-title')->text()));
        self::assertSame('Февраль – Июль 2026', trim($pageHeader->filter('.text-secondary')->text()));
        $headerActions = $pageHeader->filter('.row.g-2.align-items-start > .col-auto.ms-auto.d-print-none');
        self::assertCount(1, $headerActions);
        self::assertCount(1, $headerActions->filter('.btn-list'));
        self::assertCount(2, $headerActions->filter('.btn-list > a.btn.btn-outline-secondary'));
        self::assertCount(1, $crawler->filter('main.container-xl'));
        self::assertCount(0, $crawler->filter('.page-body .page-body'));
        self::assertCount(1, $crawler->filter('form#pl-preview-filter-form.card.card-body.pl-preview-controls-card'));
        self::assertCount(1, $crawler->filter('div.card.table-responsive > table.pl-table'));
        self::assertCount(0, $crawler->filter('#pl-preview-filters-offcanvas'));
        self::assertSame(
            '2026-02-10',
            $crawler->filter('#pl-preview-filter-form input[data-pl-exact-from]')->attr('value'),
        );
        self::assertSame(
            '2026-07-15',
            $crawler->filter('#pl-preview-filter-form input[data-pl-exact-to]')->attr('value'),
        );
        self::assertSame(
            'quarter',
            $crawler->filter('#pl-preview-filter-form input[data-pl-grouping]')->attr('value'),
        );
        self::assertCount(
            1,
            $crawler->filter('button[data-pl-set="grouping"][data-pl-value="quarter"].is-active'),
        );
        self::assertCount(1, $crawler->filter('button[data-pl-set="grouping"][data-pl-value="month"]'));
        $periodPresets = $crawler->filter('button[data-pl-period-from][data-pl-period-to]');
        self::assertCount(4, $periodPresets);
        $previousMonthPreset = $periodPresets->reduce(
            static fn (Crawler $button): bool => 'Предыдущий месяц' === trim($button->text()),
        );
        self::assertCount(1, $previousMonthPreset);
        self::assertSame(
            $firstOfThisMonth->modify('-1 month')->format('Y-m-d'),
            $previousMonthPreset->attr('data-pl-period-from'),
        );
        self::assertSame(
            $firstOfThisMonth->modify('-1 day')->format('Y-m-d'),
            $previousMonthPreset->attr('data-pl-period-to'),
        );
        self::assertGreaterThanOrEqual(2, $crawler->filter('input[name="projectDirectionIds[]"]')->count());
        self::assertCount(1, $crawler->filter('input[name="projectDirectionIds[]"][checked]'));
        self::assertSame(
            $projectA->getId(),
            $crawler->filter('input[name="projectDirectionIds[]"][checked]')->attr('value'),
        );
        self::assertGreaterThanOrEqual(2, $crawler->filter('input[name="responsibilityCenterIds[]"]')->count());
        self::assertCount(1, $crawler->filter('input[name="responsibilityCenterIds[]"][checked]'));
        self::assertCount(1, $crawler->filter('input[name="show_meta"][checked]'));

        $jsonAction = $pageHeader->filter('a[title="Скачать отчёт в формате JSON для проверки"]');
        self::assertCount(1, $jsonAction);
        self::assertSame('JSON', trim($jsonAction->text()));
        $jsonQuery = $this->queryFromHref((string) $jsonAction->attr('href'));
        self::assertSame('2026-02-10', $jsonQuery['from']);
        self::assertSame('2026-07-15', $jsonQuery['to']);
        self::assertSame('quarter', $jsonQuery['grouping']);
        self::assertSame('periods', $jsonQuery['layout']);
        self::assertSame('1', $jsonQuery['show_meta']);
        self::assertSame('1', $jsonQuery['projectFiltersPresent']);
        self::assertSame('1', $jsonQuery['responsibilityCenterFiltersPresent']);
        self::assertSame([$projectA->getId()], $jsonQuery['projectDirectionIds']);
        self::assertSame([$centerA->getId()], $jsonQuery['responsibilityCenterIds']);

        $xlsxAction = $pageHeader->filter('[data-testid="btn-export-xlsx"]');
        self::assertCount(1, $xlsxAction);
        self::assertSame('XLSX', trim($xlsxAction->text()));
        self::assertSame($jsonQuery, $this->queryFromHref((string) $xlsxAction->attr('href')));

        self::assertCount(0, $crawler->filter('form[action$="/finance/report/preview/recalc"]'));
        self::assertCount(0, $crawler->filter('#recalc_from'));

        $availableProjectIds = $crawler->filter('#pl-preview-filter-form input[name="projectDirectionIds[]"]')->each(
            static fn (Crawler $input): ?string => $input->attr('value'),
        );
        $availableCenterIds = $crawler->filter('#pl-preview-filter-form input[name="responsibilityCenterIds[]"]')->each(
            static fn (Crawler $input): ?string => $input->attr('value'),
        );
        $resetQuery = $this->queryFromHref((string) $crawler->filter('a.pl-preview-reset')->attr('href'));
        self::assertSame('2026-02-10', $resetQuery['from']);
        self::assertSame('2026-07-15', $resetQuery['to']);
        self::assertSame('quarter', $resetQuery['grouping']);
        self::assertSame('periods', $resetQuery['layout']);
        self::assertSame('1', $resetQuery['projectFiltersPresent']);
        self::assertSame('1', $resetQuery['responsibilityCenterFiltersPresent']);
        self::assertEqualsCanonicalizing($availableProjectIds, $resetQuery['projectDirectionIds']);
        self::assertEqualsCanonicalizing($availableCenterIds, $resetQuery['responsibilityCenterIds']);
        self::assertArrayNotHasKey('show_meta', $resetQuery);

        $noneSelected = $client->request('GET', '/finance/report/preview', [
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

        $legacyProjects = $client->request('GET', '/finance/report/preview', [
            'layout' => 'projects',
            'projectDirectionId' => $projectA->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Проекты: Все',
            $legacyProjects->filter('.pl-preview-filters-row details')->eq(0)->filter('summary')->text(),
        );
        self::assertSame(
            $legacyProjects->filter('input[name="projectDirectionIds[]"]')->count(),
            $legacyProjects->filter('input[name="projectDirectionIds[]"][checked]')->count(),
        );
        $legacyJsonQuery = $this->queryFromHref((string) $legacyProjects->filter('a[title="Скачать отчёт в формате JSON для проверки"]')->attr('href'));
        self::assertSame($projectA->getId(), $legacyJsonQuery['projectDirectionId']);
        self::assertArrayNotHasKey('projectFiltersPresent', $legacyJsonQuery);
    }

    public function testXlsxExportUsesCurrentLayoutFiltersAndNumericValues(): void
    {
        $client = static::createClient();
        $company = $this->loginWithCompany($client, 'pl-report-preview-xlsx@example.test');
        $projectA = new ProjectDirection('33333333-3333-3333-3333-000000000971', $company, 'Alpha project');
        $projectB = new ProjectDirection('33333333-3333-3333-3333-000000000972', $company, 'Beta project');
        $category = (new PLCategory('33333333-3333-3333-3333-000000000973', $company))
            ->setName('XLSX revenue')
            ->setCode('XLSX_REVENUE')
            ->setFormat(PLValueFormat::PERCENT)
            ->setFlow(PLFlow::INCOME);
        $totalA = (new PLDailyTotal(
            '33333333-3333-3333-3333-000000000974',
            $company,
            $projectA,
            new \DateTimeImmutable('2026-08-10'),
            $category,
        ))->setAmountIncome('0.25');
        $totalB = (new PLDailyTotal(
            '33333333-3333-3333-3333-000000000975',
            $company,
            $projectB,
            new \DateTimeImmutable('2026-08-10'),
            $category,
        ))->setAmountIncome('0.50');

        $foreignUser = UserBuilder::aUser()->withIndex(2)->withEmail('pl-report-preview-xlsx-foreign@example.test')->build();
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($foreignUser)->build();
        $foreignProject = new ProjectDirection(
            '33333333-3333-3333-3333-000000000976',
            $foreignCompany,
            'Foreign project',
        );

        foreach ([$projectA, $projectB, $category, $totalA, $totalB, $foreignUser, $foreignCompany, $foreignProject] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->request('GET', '/finance/report/preview/xlsx', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'grouping' => 'month',
            'layout' => 'periods',
            'show_meta' => '1',
            'projectFiltersPresent' => '1',
            'projectDirectionIds' => [$projectA->getId(), $foreignProject->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $client->getResponse()->headers->get('Content-Type'),
        );
        self::assertStringContainsString(
            'pl-report_periods_2026-08-01_2026-08-31.xlsx',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        $periodContent = (string) $client->getInternalResponse()->getContent();
        $periodRows = $this->readXlsxRows($periodContent);
        self::assertSame(['Строка', 'Код', 'Тип', '2026-08'], $periodRows[0]);
        $periodRow = $this->xlsxRowByValue($periodRows, 1, 'XLSX_REVENUE');
        self::assertSame('XLSX revenue', $periodRow[0]);
        self::assertSame(0.25, $periodRow[3]);
        self::assertSame('0.0%;(0.0%);0.0%', $this->xlsxNumberFormat($periodContent, 'D2'));

        $client->request('GET', '/finance/report/preview/xlsx', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'layout' => 'projects',
            'show_meta' => '1',
            'projectFiltersPresent' => '1',
            'projectDirectionIds' => [$projectA->getId(), $projectB->getId(), $foreignProject->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'pl-report_projects_2026-08-01_2026-08-31.xlsx',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        $projectContent = (string) $client->getInternalResponse()->getContent();
        $projectRows = $this->readXlsxRows($projectContent);
        self::assertSame(
            ['Строка', 'Код', 'Тип', 'Alpha project', 'Beta project', 'Итого'],
            $projectRows[0],
        );
        self::assertNotContains('Foreign project', $projectRows[0]);
        $projectRow = $this->xlsxRowByValue($projectRows, 1, 'XLSX_REVENUE');
        self::assertSame('XLSX revenue', $projectRow[0]);
        self::assertSame(0.25, $projectRow[3]);
        self::assertSame(0.5, $projectRow[4]);
        self::assertSame(0.75, $projectRow[5]);
        self::assertSame('0.0%;(0.0%);0.0%', $this->xlsxNumberFormat($projectContent, 'D2'));
    }

    public function testProjectsLayoutUsesDirectoryOrderForSelectedProjects(): void
    {
        $client = static::createClient();
        $company = $this->loginWithCompany($client, 'pl-report-preview-project-order@example.test');
        $parent = new ProjectDirection('33333333-3333-3333-3333-000000000941', $company, 'Parent project');
        // Names oppose sort values so sorting alphabetically would fail this regression test.
        $laterProject = (new ProjectDirection(
            '33333333-3333-3333-3333-000000000942',
            $company,
            'Alpha project',
        ))
            ->setParent($parent)
            ->setSort(20);
        $earlierProject = (new ProjectDirection(
            '33333333-3333-3333-3333-000000000943',
            $company,
            'Zulu project',
        ))
            ->setParent($parent)
            ->setSort(10);

        foreach ([$parent, $laterProject, $earlierProject] as $project) {
            $this->em()->persist($project);
        }
        $this->em()->flush();

        $query = [
            'layout' => 'projects',
            'projectFiltersPresent' => '1',
            'projectDirectionIds' => [$laterProject->getId(), $earlierProject->getId()],
        ];
        $crawler = $client->request('GET', '/finance/report/preview', $query);

        self::assertResponseIsSuccessful();
        $headers = $crawler->filter('table.pl-table thead th')->each(
            static fn (Crawler $header): string => trim($header->text()),
        );
        self::assertSame(
            ['Zulu project', 'Alpha project'],
            array_slice($headers, 1, -1),
        );

        $client->request('GET', '/finance/report/preview/json', $query);

        self::assertResponseIsSuccessful();
        $payload = $this->responsePayload($client);
        $expectedProjectIds = [$earlierProject->getId(), $laterProject->getId()];
        self::assertSame($expectedProjectIds, array_column($payload['projects'], 'id'));
        self::assertSame($expectedProjectIds, $payload['meta']['project_direction_ids']);
    }

    public function testPreviewHeaderFormatsPeriodWithRussianMonthNames(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, 'pl-report-preview-header-period@example.test');

        foreach ([
            ['2026-02-10', '2026-02-28', 'Февраль 2026'],
            ['2026-02-10', '2026-07-15', 'Февраль – Июль 2026'],
            ['2025-12-15', '2026-02-10', 'Декабрь 2025 – Февраль 2026'],
            ['2025-02-01', '2026-02-28', 'Февраль 2025 – Февраль 2026'],
        ] as [$from, $to, $expectedLabel]) {
            $crawler = $client->request('GET', '/finance/report/preview', [
                'from' => $from,
                'to' => $to,
            ]);

            self::assertResponseIsSuccessful(sprintf('%s..%s', $from, $to));
            self::assertSame(
                $expectedLabel,
                trim($crawler->filter('.page-header .text-secondary')->text()),
            );
        }
    }

    public function testLegacyDayAndWeekRangesStayExactUntilMonthControlsChange(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, 'pl-report-preview-legacy-range@example.test');

        foreach (['day', 'week'] as $grouping) {
            $crawler = $client->request('GET', '/finance/report/preview', [
                'from' => '2026-02-10',
                'to' => '2026-03-15',
                'grouping' => $grouping,
            ]);

            self::assertResponseIsSuccessful($grouping);
            self::assertSame(
                '2026-02-10',
                $crawler->filter('#pl-preview-filter-form input[data-pl-exact-from]')->attr('value'),
            );
            self::assertSame(
                '2026-03-15',
                $crawler->filter('#pl-preview-filter-form input[data-pl-exact-to]')->attr('value'),
            );
            self::assertSame(
                $grouping,
                $crawler->filter('#pl-preview-filter-form input[data-pl-grouping]')->attr('value'),
            );
            self::assertCount(0, $crawler->filter('button[data-pl-set="grouping"].is-active'));
            self::assertSame('2026-02', $crawler->filter('input[data-pl-month-from]')->attr('value'));
            self::assertSame('2026-03', $crawler->filter('input[data-pl-month-to]')->attr('value'));

            $jsonQuery = $this->queryFromHref((string) $crawler->filter('a[title="Скачать отчёт в формате JSON для проверки"]')->attr('href'));
            self::assertArrayNotHasKey('projectFiltersPresent', $jsonQuery);
            self::assertArrayNotHasKey('responsibilityCenterFiltersPresent', $jsonQuery);
            self::assertArrayNotHasKey('projectDirectionIds', $jsonQuery);
            self::assertArrayNotHasKey('responsibilityCenterIds', $jsonQuery);

            self::assertCount(0, $crawler->filter('form[action$="/finance/report/preview/recalc"]'));
        }
    }

    public function testQuarterGroupingAndPluralFiltersAreValidatedForActiveCompany(): void
    {
        $client = static::createClient();
        $company = $this->loginWithCompany($client, 'pl-report-preview-multifilter@example.test');
        $projectA = new ProjectDirection('33333333-3333-3333-3333-000000000911', $company, 'Project A');
        $projectB = new ProjectDirection('33333333-3333-3333-3333-000000000912', $company, 'Project B');
        $centerA = new FinancialResponsibilityCenter((string) $company->getId(), 'CENTER_A', 'Center A');
        $centerB = new FinancialResponsibilityCenter((string) $company->getId(), 'CENTER_B', 'Center B');
        $category = (new PLCategory('33333333-3333-3333-3333-000000000914', $company))
            ->setName('Revenue test')
            ->setCode('REV_TEST')
            ->setFlow(PLFlow::INCOME);
        $allocatedTotal = new PLDailyTotal(
            '33333333-3333-3333-3333-000000000915',
            $company,
            $projectA,
            new \DateTimeImmutable('2026-08-01'),
            $category,
        );
        $allocatedTotal
            ->setAmountIncome('100.00')
            ->setAmountExpense('0.00')
            ->setResponsibilityCenterId($centerA->getId());
        $unallocatedTotal = new PLDailyTotal(
            '33333333-3333-3333-3333-000000000916',
            $company,
            $projectB,
            new \DateTimeImmutable('2026-08-01'),
            $category,
        );
        $unallocatedTotal
            ->setAmountIncome('25.00')
            ->setAmountExpense('0.00')
            ->setResponsibilityCenterId(null);

        $foreignUser = UserBuilder::aUser()->withIndex(2)->withEmail('pl-report-preview-foreign@example.test')->build();
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($foreignUser)->build();
        $foreignProject = new ProjectDirection('33333333-3333-3333-3333-000000000913', $foreignCompany, 'Foreign project');
        $foreignCenter = new FinancialResponsibilityCenter((string) $foreignCompany->getId(), 'FOREIGN', 'Foreign center');

        foreach ([$projectA, $projectB, $centerA, $centerB, $category, $allocatedTotal, $unallocatedTotal, $foreignUser, $foreignCompany, $foreignProject, $foreignCenter] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->request('GET', '/finance/report/preview');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/finance/report/preview/json', [
            'from' => '2026-02-01',
            'to' => '2026-07-15',
            'grouping' => 'quarter',
            'dimensionFiltersPresent' => '1',
            'projectDirectionIds' => [$projectA->getId(), $foreignProject->getId()],
            'responsibilityCenterIds' => [$centerA->getId(), $foreignCenter->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->responsePayload($client);
        self::assertSame('quarter', $payload['meta']['grouping']);
        self::assertSame([$projectA->getId()], $payload['meta']['project_direction_ids']);
        self::assertSame([$centerA->getId()], $payload['meta']['responsibility_center_ids']);
        self::assertSame($projectA->getId(), $payload['meta']['project_direction_id']);
        self::assertSame($centerA->getId(), $payload['meta']['responsibility_center_id']);
        self::assertSame(
            [
                'I кв. 2026 (01.02.2026 — 31.03.2026)',
                'II кв. 2026',
                'III кв. 2026 (01.07.2026 — 15.07.2026)',
            ],
            array_column($payload['periods'], 'label'),
        );

        $client->request('GET', '/finance/report/preview/json', [
            'from' => '2026-02-01',
            'to' => '2026-07-15',
            'layout' => 'projects',
            'dimensionFiltersPresent' => '1',
            'projectDirectionIds' => [$projectA->getId()],
            'responsibilityCenterIds' => [$centerA->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $projectsPayload = $this->responsePayload($client);
        self::assertSame([$projectA->getId()], array_column($projectsPayload['projects'], 'id'));

        $client->request('GET', '/finance/report/preview/json', [
            'projectDirectionId' => $projectA->getId(),
            'responsibilityCenterIds' => [$centerA->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $mixedPayload = $this->responsePayload($client);
        self::assertSame([$projectA->getId()], $mixedPayload['meta']['project_direction_ids']);
        self::assertSame([$centerA->getId()], $mixedPayload['meta']['responsibility_center_ids']);

        $client->request('GET', '/finance/report/preview/json', [
            'projectFiltersPresent' => '1',
            'responsibilityCenterId' => $centerA->getId(),
        ]);

        self::assertResponseIsSuccessful();
        $perDimensionPayload = $this->responsePayload($client);
        self::assertSame([], $perDimensionPayload['meta']['project_direction_ids']);
        self::assertSame([$centerA->getId()], $perDimensionPayload['meta']['responsibility_center_ids']);

        $client->request('GET', '/finance/report/preview/json', [
            'dimensionFiltersPresent' => '1',
            'projectDirectionIds' => [],
            'responsibilityCenterIds' => [],
        ]);

        self::assertResponseIsSuccessful();
        $emptyPayload = $this->responsePayload($client);
        self::assertSame([], $emptyPayload['meta']['project_direction_ids']);
        self::assertSame([], $emptyPayload['meta']['responsibility_center_ids']);

        $client->request('GET', '/finance/report/preview/json', [
            'dimensionFiltersPresent' => '1',
            'projectDirectionIds' => [$projectA->getId(), $projectB->getId()],
            'responsibilityCenterIds' => [$centerA->getId(), $centerB->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $allSelectedPayload = $this->responsePayload($client);
        self::assertNull($allSelectedPayload['meta']['project_direction_ids']);
        self::assertNull($allSelectedPayload['meta']['responsibility_center_ids']);

        $client->request('GET', '/finance/report/preview/json');
        self::assertResponseIsSuccessful();
        self::assertSame($this->responsePayload($client)['rows'], $allSelectedPayload['rows']);
    }

    public function testLegacySingularPreviewJsonMetadataRemainsCompatible(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, 'pl-report-preview-legacy-filter@example.test');
        $invalidProjectId = '33333333-3333-3333-3333-000000000999';

        $client->request('GET', '/finance/report/preview/json', [
            'grouping' => 'day',
            'projectDirectionId' => $invalidProjectId,
            'responsibilityCenterId' => '33333333-3333-3333-3333-000000000998',
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->responsePayload($client);
        self::assertSame('day', $payload['meta']['grouping']);
        self::assertSame($invalidProjectId, $payload['meta']['project_direction_id']);
        self::assertNull($payload['meta']['responsibility_center_id']);
        self::assertNull($payload['meta']['project_direction_ids']);
        self::assertNull($payload['meta']['responsibility_center_ids']);
    }

    public function testExplicitEmptyPluralFiltersStayEmptyWithoutAvailableChoices(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, 'pl-report-preview-empty-choices@example.test');

        $client->request('GET', '/finance/report/preview/json', [
            'dimensionFiltersPresent' => '1',
            'projectDirectionIds' => [],
            'responsibilityCenterIds' => [],
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->responsePayload($client);
        self::assertSame([], $payload['meta']['project_direction_ids']);
        self::assertSame([], $payload['meta']['responsibility_center_ids']);
    }

    public function testRecalcRedirectPreservesPluralFilterState(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, 'pl-report-preview-recalc-redirect@example.test');
        $projectIds = ['33333333-3333-3333-3333-000000000921', '33333333-3333-3333-3333-000000000922'];
        $centerIds = ['33333333-3333-3333-3333-000000000923'];

        $client->request('POST', '/finance/report/preview/recalc', [
            '_token' => 'invalid',
            'from' => '2026-01-01',
            'to' => '2026-03-31',
            'grouping' => 'quarter',
            'layout' => 'projects',
            'show_meta' => '1',
            'dimensionFiltersPresent' => '1',
            'projectDirectionIds' => $projectIds,
            'responsibilityCenterIds' => $centerIds,
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('quarter', $query['grouping']);
        self::assertSame('projects', $query['layout']);
        self::assertSame('1', $query['show_meta']);
        self::assertSame('1', $query['dimensionFiltersPresent']);
        self::assertSame($projectIds, $query['projectDirectionIds']);
        self::assertSame($centerIds, $query['responsibilityCenterIds']);

        $client->request('POST', '/finance/report/preview/recalc', [
            '_token' => 'invalid',
            'projectDirectionIds' => $projectIds,
            'responsibilityCenterId' => $centerIds[0],
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $mixedQuery);
        self::assertArrayNotHasKey('dimensionFiltersPresent', $mixedQuery);
        self::assertArrayNotHasKey('responsibilityCenterIds', $mixedQuery);
        self::assertSame($projectIds, $mixedQuery['projectDirectionIds']);
        self::assertSame($centerIds[0], $mixedQuery['responsibilityCenterId']);

        $client->request('POST', '/finance/report/preview/recalc', [
            '_token' => 'invalid',
            'projectFiltersPresent' => '1',
            'responsibilityCenterFiltersPresent' => '1',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $perDimensionQuery);
        self::assertSame('1', $perDimensionQuery['projectFiltersPresent']);
        self::assertSame('1', $perDimensionQuery['responsibilityCenterFiltersPresent']);
        self::assertArrayNotHasKey('projectDirectionIds', $perDimensionQuery);
        self::assertArrayNotHasKey('responsibilityCenterIds', $perDimensionQuery);
    }

    private function tableStyle(KernelBrowser $client, string $url): string
    {
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful($url);

        return (string) $crawler->filter('table.pl-table')->attr('style');
    }

    /** @return array<string, mixed> */
    private function responsePayload(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function queryFromHref(string $href): array
    {
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @return list<list<mixed>>
     */
    private function readXlsxRows(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pl_report_export_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        $reader = new Reader();
        $reader->open($path);

        try {
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(
                        static fn ($cell) => $cell->getValue(),
                        $row->getCells(),
                    );
                }

                break;
            }
        } finally {
            $reader->close();
            unlink($path);
        }

        return $rows;
    }

    /**
     * @param list<list<mixed>> $rows
     *
     * @return list<mixed>
     */
    private function xlsxRowByValue(array $rows, int $column, mixed $value): array
    {
        foreach ($rows as $row) {
            if (($row[$column] ?? null) === $value) {
                return $row;
            }
        }

        self::fail(sprintf('XLSX row with value "%s" in column %d not found.', (string) $value, $column));
    }

    private function xlsxNumberFormat(string $content, string $coordinate): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pl_report_format_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        try {
            $spreadsheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);

            return $spreadsheet->getActiveSheet()->getStyle($coordinate)->getNumberFormat()->getFormatCode();
        } finally {
            if (isset($spreadsheet)) {
                $spreadsheet->disconnectWorksheets();
            }
            unlink($path);
        }
    }

    private function loginWithCompany(KernelBrowser $client, string $email): object
    {
        $this->resetDb();

        $user = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return $company;
    }
}
