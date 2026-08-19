<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
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
