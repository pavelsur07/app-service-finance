<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report\Cashflow;

use App\Cash\Application\DTO\CashflowCategoryNode;
use App\Cash\Application\DTO\CashflowReportRow;
use App\Cash\Application\DTO\CashflowReportRowFilter;
use App\Cash\Application\DTO\MoneyAccountOpeningBalance;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Repository\ProjectDirectionRepository;
use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportParams;
use App\Tests\Support\Mock\MocksFinalClasses;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Запросы отчёта строит Cash (`CashReportQuery`, проверяется интеграционным
 * `CashFacadeCashflowReportReadTest`); здесь — какие фильтры отчёт передаёт фасаду и
 * как сводит строки в итоги.
 */
final class CashflowReportBuilderTest extends TestCase
{
    use MocksFinalClasses;

    /** @var list<CashflowReportRowFilter> */
    private array $filters = [];

    public function testBuildSumsCategoryTotalsWithoutFiltersInOneQuery(): void
    {
        $company = $this->company('cashflow-report@example.com');
        $category = $this->category('Operations');
        $facade = $this->facade([$category], [[$this->row($category, '100.00', 'USD')]], []);

        $payload = $this->builder($facade, $this->projectRepository(never: true))->build(new CashflowReportParams(
            $company,
            'day',
            new \DateTimeImmutable('2026-01-10'),
            new \DateTimeImmutable('2026-01-10'),
        ));

        self::assertEquals([new CashflowReportRowFilter()], $this->filters);
        self::assertSame(100.0, $payload['categoryTotals'][$category->id]['totals']['USD'][0]);
        self::assertSame([$category], $payload['categories']);
    }

    public function testBuildFiltersTransactionsByResponsibilityCenter(): void
    {
        $company = $this->company('cashflow-report-filter@example.com');
        $category = $this->category('Operations');
        $centerId = '11111111-1111-4111-8111-111111111111';
        $filtered = [$this->row($category, '100.00', 'RUB', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Продажа компьютеров', $centerId)];
        $companyRows = [
            ...$filtered,
            $this->row($category, '50.00', 'RUB', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'Сервисные услуги', '22222222-2222-4222-8222-222222222222'),
        ];
        $facade = $this->facade([$category], [$filtered, $companyRows], [
            new MoneyAccountOpeningBalance(Uuid::uuid4()->toString(), 'RUB', '1000.00'),
        ]);

        $payload = $this->builder($facade, $this->projectRepository(never: true))->build(new CashflowReportParams(
            $company,
            'day',
            new \DateTimeImmutable('2026-01-10'),
            new \DateTimeImmutable('2026-01-10'),
            $centerId,
        ));

        self::assertEquals([new CashflowReportRowFilter(responsibilityCenterId: $centerId), new CashflowReportRowFilter()], $this->filters);
        self::assertSame($centerId, $payload['responsibility_center_id']);
        self::assertSame(100.0, $payload['categoryTotals'][$category->id]['totals']['RUB'][0]);
        self::assertSame(1000.0, $payload['openings']['RUB'][0]);
        self::assertSame(1150.0, $payload['closings']['RUB'][0]);
        self::assertSame(['RUB'], $payload['projectCenterMatrix']['currencies']);
        self::assertSame('Продажа компьютеров', $payload['projectCenterMatrix']['rowsByProject'][0]['project_name']);
        self::assertSame($centerId, $payload['projectCenterMatrix']['rowsByProject'][0]['responsibility_center_id']);
        self::assertSame(100.0, $payload['projectCenterMatrix']['rowsByProject'][0]['totals']['RUB'][0]);
        self::assertCount(1, $payload['projectCenterMatrix']['rowsByProject']);
    }

    public function testBuildCombinesPluralProjectSubtreeAndResponsibilityCenterFilters(): void
    {
        $company = $this->company('cashflow-plural-filter@example.com');
        $parent = new ProjectDirection('11111111-1111-4111-8111-111111111111', $company, 'Parent');
        $child = (new ProjectDirection('22222222-2222-4222-8222-222222222222', $company, 'Child'))
            ->setParent($parent);
        $other = new ProjectDirection('33333333-3333-4333-8333-333333333333', $company, 'Other');
        $projects = $this->projectRepository();
        $projects->expects(self::once())
            ->method('findByCompany')
            ->with($company)
            ->willReturn([$parent, $child, $other]);
        $centerIds = [
            '44444444-4444-4444-8444-444444444444',
            '55555555-5555-4555-8555-555555555555',
        ];

        $payload = $this->builder($this->facade([], [[], []], []), $projects)->build(new CashflowReportParams(
            $company,
            'month',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            null,
            [(string) $parent->getId()],
            $centerIds,
        ));

        self::assertEquals([
            new CashflowReportRowFilter(projectDirectionIds: [(string) $parent->getId(), (string) $child->getId()], responsibilityCenterIds: $centerIds),
            new CashflowReportRowFilter(),
        ], $this->filters);
        self::assertSame([$parent->getId()], $payload['project_direction_ids']);
        self::assertSame($centerIds, $payload['responsibility_center_ids']);
    }

    public function testBuildDoesNotIssueInvalidQueryForExplicitEmptyFilter(): void
    {
        $payload = $this->builder($this->facade([], [[]], []), $this->projectRepository(never: true))->build(new CashflowReportParams(
            $this->company('cashflow-empty-filter@example.com'),
            'month',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            null,
            [],
        ));

        // Пустой фильтр проектов — строки отчёта не запрашиваются вовсе, только итоги компании.
        self::assertEquals([new CashflowReportRowFilter()], $this->filters);
        self::assertSame([], $payload['project_direction_ids']);
        self::assertSame([], $payload['projectCenterMatrix']['rowsByProject']);
    }

    public function testBuildCombinesPluralProjectFilterWithLegacyResponsibilityCenter(): void
    {
        $company = $this->company('cashflow-mixed-filter@example.com');
        $projectA = new ProjectDirection('66666666-6666-4666-8666-666666666661', $company, 'Project A');
        $projectB = new ProjectDirection('66666666-6666-4666-8666-666666666662', $company, 'Project B');
        $centerId = '66666666-6666-4666-8666-666666666663';

        $this->builder($this->facade([], [[], []], []), $this->projectRepository(never: true))->build(new CashflowReportParams(
            $company,
            'month',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            $centerId,
            [(string) $projectA->getId()],
            null,
            [$projectA, $projectB],
        ));

        self::assertEquals(
            new CashflowReportRowFilter(projectDirectionIds: [(string) $projectA->getId()], responsibilityCenterId: $centerId),
            $this->filters[0],
        );
    }

    public function testBuildRejectsForeignProjectFromSuppliedCatalogue(): void
    {
        $company = $this->company('cashflow-company@example.com');
        $foreignProject = new ProjectDirection(
            '77777777-7777-4777-8777-777777777771',
            $this->company('cashflow-foreign@example.com'),
            'Foreign Project',
        );

        $payload = $this->builder($this->facade([], [[]], []), $this->projectRepository(never: true))->build(new CashflowReportParams(
            $company,
            'month',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            null,
            [(string) $foreignProject->getId()],
            null,
            [$foreignProject],
        ));

        self::assertEquals([new CashflowReportRowFilter()], $this->filters);
        self::assertSame([], $payload['projectCenterMatrix']['rowsByProject']);
        self::assertSame([$foreignProject->getId()], $payload['project_direction_ids']);
    }

    public function testDashboardReconciliationScopesRowsCategoriesAndOpenings(): void
    {
        $company = $this->company('cashflow-dashboard@example.com');
        $operating = $this->category('Operations');
        $technical = new CashflowCategoryNode(Uuid::uuid4()->toString(), 'Tech', 1, null, CashflowFlowKind::TECHNICAL, null, []);
        $facade = $this->facade([$operating, $technical], [[], []], [], expectedOpeningCurrency: 'RUB');
        $facade->expects(self::once())->method('sumGrossTurnoverExcludingTransfers')
            ->with((string) $company->getId(), self::anything(), self::anything(), 'RUB', CashflowFlowKind::OPERATING)
            ->willReturn(['inflow' => '10.00', 'outflow' => '4.00']);

        $payload = $this->builder($facade, $this->projectRepository(never: true))->build(new CashflowReportParams(
            $company,
            'month',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            dashboardActivity: 'operating',
            dashboardCurrency: FiatCurrency::RUB,
        ));

        self::assertEquals([
            new CashflowReportRowFilter(dashboardScope: true, dashboardCurrency: 'RUB', dashboardFlowKind: CashflowFlowKind::OPERATING),
            new CashflowReportRowFilter(currency: 'RUB'),
        ], $this->filters);
        self::assertSame([$operating], $payload['categories']);
        self::assertSame('6.00', $payload['dashboardReconciliation']['net']);
    }

    /**
     * @param list<CashflowCategoryNode> $categories
     * @param list<list<CashflowReportRow>> $rowsPerCall по вызову listCashflowReportRows
     * @param list<MoneyAccountOpeningBalance> $openings
     */
    private function facade(array $categories, array $rowsPerCall, array $openings, ?string $expectedOpeningCurrency = null): MockObject
    {
        $facade = $this->createFinalMock(CashFacade::class);
        $facade->method('getCashflowCategoryTree')->willReturn($categories);
        $facade->expects(self::exactly(\count($rowsPerCall)))
            ->method('listCashflowReportRows')
            ->willReturnCallback(function (string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, CashflowReportRowFilter $filter) use (&$rowsPerCall): array {
                $this->filters[] = $filter;

                return array_shift($rowsPerCall) ?? [];
            });
        $facade->expects(self::once())
            ->method('getReportOpeningBalances')
            ->with(self::anything(), self::anything(), $expectedOpeningCurrency)
            ->willReturn($openings);

        return $facade;
    }

    private function builder(MockObject $facade, MockObject $projects): CashflowReportBuilder
    {
        return new CashflowReportBuilder(
            self::asInstanceOf($facade, CashFacade::class),
            self::asInstanceOf($projects, ProjectDirectionRepository::class),
        );
    }

    private function projectRepository(bool $never = false): MockObject
    {
        $projects = $this->createMock(ProjectDirectionRepository::class);
        if ($never) {
            $projects->expects(self::never())->method('findByCompany');
        }

        return $projects;
    }

    private function company(string $email): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setPassword('pass');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Cashflow Co');

        return $company;
    }

    private function category(string $name): CashflowCategoryNode
    {
        return new CashflowCategoryNode(Uuid::uuid4()->toString(), $name, 1, null, CashflowFlowKind::OPERATING, null, []);
    }

    private function row(
        CashflowCategoryNode $category,
        string $amount,
        string $currency,
        ?string $projectId = null,
        ?string $projectName = null,
        ?string $centerId = null,
    ): CashflowReportRow {
        return new CashflowReportRow($category->id, $projectId, $projectName, $centerId, CashDirection::INFLOW, $amount, $currency, new \DateTimeImmutable('2026-01-10 12:00:00'));
    }
}
