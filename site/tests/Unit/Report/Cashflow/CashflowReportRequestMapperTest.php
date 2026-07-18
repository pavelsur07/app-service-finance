<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report\Cashflow;

use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Enum\FinancialResponsibilityCenterStatus;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Report\Cashflow\CashflowReportRequestMapper;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

final class CashflowReportRequestMapperTest extends TestCase
{
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

        $params = (new CashflowReportRequestMapper($facade))->fromRequest(
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

        $params = (new CashflowReportRequestMapper($facade))->fromRequest(
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

        $params = (new CashflowReportRequestMapper($facade))->fromRequest(
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

        $params = (new CashflowReportRequestMapper($facade))->fromRequest(
            new Request([
                'from' => '2026-01-01',
                'to' => '2026-01-31',
                'responsibilityCenterId' => $centerId,
            ]),
            $company,
        );

        self::assertNull($params->responsibilityCenterId);
    }

    private function createCompany(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('cashflow-request-mapper@example.com');
        $user->setPassword('pass');

        return new Company(Uuid::uuid4()->toString(), $user);
    }
}
