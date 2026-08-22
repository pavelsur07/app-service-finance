<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report\Cashflow;

use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Enum\FinancialResponsibilityCenterStatus;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\ProjectDirectionRepository;
use App\Report\Cashflow\CashflowReportRequestMapper;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

final class CashflowReportRequestMapperTest extends TestCase
{
    public function testMapsAllowedDashboardReconciliationAndIgnoresIncompatibleFilters(): void
    {
        $company = $this->createCompany();
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade->expects(self::never())->method('findByIdAndCompany');
        $facade->expects(self::never())->method('getActiveChoices');
        $projects = $this->createMock(ProjectDirectionRepository::class);
        $projects->expects(self::never())->method('findByCompany');

        $params = (new CashflowReportRequestMapper($facade, $projects))->fromRequest(
            new Request([
                'from' => '2026-08-22',
                'to' => '2026-07-24',
                'group' => 'day',
                'reconcile' => 'dashboard',
                'activity' => 'operating',
                'currency' => 'rub',
                'projectFiltersPresent' => '1',
                'responsibilityCenterId' => '11111111-1111-4111-8111-111111111111',
            ]),
            $company,
            allowDashboardReconciliation: true,
        );

        self::assertTrue($params->isDashboardReconciliation());
        self::assertSame('operating', $params->dashboardActivity);
        self::assertSame('RUB', $params->dashboardCurrency?->value);
        self::assertSame('OPERATING', $params->dashboardFlowKind()?->value);
        self::assertSame('2026-07-24', $params->from->format('Y-m-d'));
        self::assertSame('2026-08-22', $params->to->format('Y-m-d'));
        self::assertNull($params->projectDirectionIds);
        self::assertNull($params->responsibilityCenterIds);
        self::assertNull($params->responsibilityCenterId);
    }

    public function testIgnoresDashboardReconciliationUnlessExplicitlyAllowed(): void
    {
        $params = (new CashflowReportRequestMapper(
            $this->createMock(FinancialResponsibilityCenterFacade::class),
            $this->unusedProjectRepository(),
        ))->fromRequest(new Request([
            'reconcile' => 'dashboard',
            'activity' => 'all',
            'currency' => 'RUB',
        ]), $this->createCompany());

        self::assertFalse($params->isDashboardReconciliation());
        self::assertNull($params->dashboardActivity);
        self::assertNull($params->dashboardCurrency);
    }

    public function testRejectsIncompleteDashboardReconciliationScope(): void
    {
        $params = (new CashflowReportRequestMapper(
            $this->createMock(FinancialResponsibilityCenterFacade::class),
            $this->unusedProjectRepository(),
        ))->fromRequest(
            new Request([
                'reconcile' => 'dashboard',
                'activity' => 'unknown',
                'currency' => 'RUB',
            ]),
            $this->createCompany(),
            allowDashboardReconciliation: true,
        );

        self::assertFalse($params->isDashboardReconciliation());
    }

    public function testMapsActiveCompanyResponsibilityCenterFilter(): void
    {
        $company = $this->createCompany();
        $centerId = '11111111-1111-4111-8111-111111111111';
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade->expects(self::once())
            ->method('findByIdAndCompany')
            ->with($centerId, (string) $company->getId())
            ->willReturn(new FinancialResponsibilityCenterDTO(
                id: $centerId,
                code: 'CFO_TEST',
                name: 'Test CFO',
                sort: 10,
                status: FinancialResponsibilityCenterStatus::ACTIVE->value,
                system: false,
                version: 1,
            ));

        $params = (new CashflowReportRequestMapper($facade, $this->unusedProjectRepository()))->fromRequest(
            new Request([
                'from' => '2026-01-01',
                'to' => '2026-01-31',
                'responsibilityCenterId' => $centerId,
            ]),
            $company,
        );

        self::assertSame($centerId, $params->responsibilityCenterId);
    }

    public function testIgnoresInvalidResponsibilityCenterFilter(): void
    {
        $company = $this->createCompany();
        $centerId = '22222222-2222-4222-8222-222222222222';
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade->expects(self::once())
            ->method('findByIdAndCompany')
            ->with($centerId, (string) $company->getId())
            ->willReturn(null);

        $params = (new CashflowReportRequestMapper($facade, $this->unusedProjectRepository()))->fromRequest(
            new Request([
                'from' => '2026-01-01',
                'to' => '2026-01-31',
                'responsibilityCenterId' => $centerId,
            ]),
            $company,
        );

        self::assertNull($params->responsibilityCenterId);
    }

    public function testIgnoresMalformedResponsibilityCenterFilter(): void
    {
        $company = $this->createCompany();
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade->expects(self::never())
            ->method('findByIdAndCompany');

        $params = (new CashflowReportRequestMapper($facade, $this->unusedProjectRepository()))->fromRequest(
            new Request([
                'from' => '2026-01-01',
                'to' => '2026-01-31',
                'responsibilityCenterId' => 'not-a-uuid',
            ]),
            $company,
        );

        self::assertNull($params->responsibilityCenterId);
    }

    public function testIgnoresArchivedResponsibilityCenterFilter(): void
    {
        $company = $this->createCompany();
        $centerId = '33333333-3333-4333-8333-333333333333';
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade->expects(self::once())
            ->method('findByIdAndCompany')
            ->with($centerId, (string) $company->getId())
            ->willReturn(new FinancialResponsibilityCenterDTO(
                id: $centerId,
                code: 'CFO_ARCHIVED',
                name: 'Archived CFO',
                sort: 20,
                status: FinancialResponsibilityCenterStatus::ARCHIVED->value,
                system: false,
                version: 1,
            ));

        $params = (new CashflowReportRequestMapper($facade, $this->unusedProjectRepository()))->fromRequest(
            new Request([
                'from' => '2026-01-01',
                'to' => '2026-01-31',
                'responsibilityCenterId' => $centerId,
            ]),
            $company,
        );

        self::assertNull($params->responsibilityCenterId);
    }

    public function testMapsPluralProjectAndResponsibilityCenterFilters(): void
    {
        $company = $this->createCompany();
        $projectA = new ProjectDirection('11111111-1111-4111-8111-111111111111', $company, 'Project A');
        $projectB = new ProjectDirection('22222222-2222-4222-8222-222222222222', $company, 'Project B');
        $projectC = new ProjectDirection('22222222-2222-4222-8222-222222222223', $company, 'Project C');
        $centerA = $this->center('33333333-3333-4333-8333-333333333333', 'Center A');
        $centerB = $this->center('44444444-4444-4444-8444-444444444444', 'Center B');
        $centerC = $this->center('44444444-4444-4444-8444-444444444445', 'Center C');

        $projects = $this->createMock(ProjectDirectionRepository::class);
        $projects->expects(self::once())
            ->method('findByCompany')
            ->with($company)
            ->willReturn([$projectA, $projectB, $projectC]);
        $centers = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $centers->expects(self::once())
            ->method('getActiveChoices')
            ->with((string) $company->getId())
            ->willReturn([$centerA, $centerB, $centerC]);
        $centers->expects(self::never())->method('findByIdAndCompany');

        $params = (new CashflowReportRequestMapper($centers, $projects))->fromRequest(new Request([
            'projectFiltersPresent' => '1',
            'projectDirectionIds' => [$projectB->getId(), $projectA->getId(), $projectA->getId(), Uuid::uuid4()->toString()],
            'responsibilityCenterFiltersPresent' => '1',
            'responsibilityCenterIds' => [$centerB->id, $centerA->id],
            'responsibilityCenterId' => $centerB->id,
        ]), $company);

        self::assertSame([$projectA->getId(), $projectB->getId()], $params->projectDirectionIds);
        self::assertSame([$centerA->id, $centerB->id], $params->responsibilityCenterIds);
        self::assertNull($params->responsibilityCenterId);
        self::assertSame([$projectA, $projectB, $projectC], $params->availableProjectDirections);
    }

    public function testProjectPluralFilterKeepsLegacyResponsibilityCenterFilter(): void
    {
        $company = $this->createCompany();
        $projectA = new ProjectDirection('99999999-9999-4999-8999-999999999991', $company, 'Project A');
        $projectB = new ProjectDirection('99999999-9999-4999-8999-999999999992', $company, 'Project B');
        $center = $this->center('99999999-9999-4999-8999-999999999993', 'Legacy Center');

        $projects = $this->createMock(ProjectDirectionRepository::class);
        $projects->expects(self::once())->method('findByCompany')->with($company)->willReturn([$projectA, $projectB]);
        $centers = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $centers->expects(self::never())->method('getActiveChoices');
        $centers->expects(self::once())
            ->method('findByIdAndCompany')
            ->with($center->id, (string) $company->getId())
            ->willReturn($center);

        $params = (new CashflowReportRequestMapper($centers, $projects))->fromRequest(new Request([
            'projectFiltersPresent' => '1',
            'projectDirectionIds' => [$projectA->getId()],
            'responsibilityCenterId' => $center->id,
        ]), $company);

        self::assertSame([$projectA->getId()], $params->projectDirectionIds);
        self::assertNull($params->responsibilityCenterIds);
        self::assertSame($center->id, $params->responsibilityCenterId);
    }

    public function testAllPluralChoicesNormalizeToUnfilteredState(): void
    {
        $company = $this->createCompany();
        $project = new ProjectDirection('55555555-5555-4555-8555-555555555555', $company, 'Project');
        $center = $this->center('66666666-6666-4666-8666-666666666666', 'Center');

        $projects = $this->createMock(ProjectDirectionRepository::class);
        $projects->method('findByCompany')->willReturn([$project]);
        $centers = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $centers->method('getActiveChoices')->willReturn([$center]);

        $params = (new CashflowReportRequestMapper($centers, $projects))->fromRequest(new Request([
            'projectDirectionIds' => [$project->getId()],
            'responsibilityCenterIds' => [$center->id],
        ]), $company);

        self::assertNull($params->projectDirectionIds);
        self::assertNull($params->responsibilityCenterIds);
        self::assertNull($params->responsibilityCenterId);
    }

    public function testExplicitEmptyPluralChoicesRemainEmpty(): void
    {
        $company = $this->createCompany();
        $project = new ProjectDirection('77777777-7777-4777-8777-777777777777', $company, 'Project');
        $center = $this->center('88888888-8888-4888-8888-888888888888', 'Center');

        $projects = $this->createMock(ProjectDirectionRepository::class);
        $projects->method('findByCompany')->willReturn([$project]);
        $centers = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $centers->method('getActiveChoices')->willReturn([$center]);

        $params = (new CashflowReportRequestMapper($centers, $projects))->fromRequest(new Request([
            'projectFiltersPresent' => '1',
            'responsibilityCenterFiltersPresent' => '1',
        ]), $company);

        self::assertSame([], $params->projectDirectionIds);
        self::assertSame([], $params->responsibilityCenterIds);
        self::assertNull($params->responsibilityCenterId);
    }

    public function testMarkersWithEmptyCataloguesRemainExplicitEmptySelections(): void
    {
        $company = $this->createCompany();
        $projects = $this->createMock(ProjectDirectionRepository::class);
        $projects->expects(self::once())->method('findByCompany')->with($company)->willReturn([]);
        $centers = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $centers->expects(self::once())
            ->method('getActiveChoices')
            ->with((string) $company->getId())
            ->willReturn([]);

        $params = (new CashflowReportRequestMapper($centers, $projects))->fromRequest(new Request([
            'projectFiltersPresent' => '1',
            'responsibilityCenterFiltersPresent' => '1',
        ]), $company);

        self::assertSame([], $params->projectDirectionIds);
        self::assertSame([], $params->responsibilityCenterIds);
        self::assertSame([], $params->availableProjectDirections);
    }

    private function unusedProjectRepository(): ProjectDirectionRepository
    {
        $repository = $this->createMock(ProjectDirectionRepository::class);
        $repository->expects(self::never())->method('findByCompany');

        return $repository;
    }

    private function center(string $id, string $name): FinancialResponsibilityCenterDTO
    {
        return new FinancialResponsibilityCenterDTO(
            id: $id,
            code: strtoupper(str_replace(' ', '_', $name)),
            name: $name,
            sort: 10,
            status: FinancialResponsibilityCenterStatus::ACTIVE->value,
            system: false,
            version: 1,
        );
    }

    private function createCompany(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('cashflow-request-mapper@example.com');
        $user->setPassword('pass');

        return new Company(Uuid::uuid4()->toString(), $user);
    }
}
