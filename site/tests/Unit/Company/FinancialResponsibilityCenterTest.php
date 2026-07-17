<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Enum\FinancialResponsibilityCenterStatus;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;

final class FinancialResponsibilityCenterTest extends TestCase
{
    public function testLifecycleKeepsCodeAndCompanyImmutable(): void
    {
        $center = new FinancialResponsibilityCenter(
            companyId: CompanyBuilder::DEFAULT_COMPANY_ID,
            code: 'CFO_SOUTH',
            name: '  Южный офис  ',
        );

        self::assertSame(CompanyBuilder::DEFAULT_COMPANY_ID, $center->getCompanyId());
        self::assertSame('CFO_SOUTH', $center->getCode());
        self::assertSame('Южный офис', $center->getName());
        self::assertSame(FinancialResponsibilityCenterStatus::ACTIVE, $center->getStatus());
        self::assertSame(1, $center->getVersion());

        $center->rename('Краснодар');
        $center->setSort(20);
        $center->archive();

        self::assertSame('Краснодар', $center->getName());
        self::assertSame(20, $center->getSort());
        self::assertSame(FinancialResponsibilityCenterStatus::ARCHIVED, $center->getStatus());

        $center->restore();
        self::assertSame(FinancialResponsibilityCenterStatus::ACTIVE, $center->getStatus());
    }

    public function testSystemCenterCannotBeRenamedOrArchived(): void
    {
        $center = new FinancialResponsibilityCenter(
            companyId: CompanyBuilder::DEFAULT_COMPANY_ID,
            code: FinancialResponsibilityCenter::CODE_GENERAL,
            name: FinancialResponsibilityCenter::NAME_GENERAL,
        );

        try {
            $center->rename('Другое имя');
            self::fail('System center rename must be rejected.');
        } catch (\DomainException $exception) {
            self::assertSame('Системный ЦФО нельзя переименовать.', $exception->getMessage());
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Системный ЦФО нельзя архивировать.');
        $center->archive();
    }

    public function testProjectPairRejectsCrossCompanyReferences(): void
    {
        $companyA = CompanyBuilder::aCompany()->withIndex(101)->build();
        $companyB = CompanyBuilder::aCompany()->withIndex(102)->build();
        $centerA = new FinancialResponsibilityCenter(
            companyId: (string) $companyA->getId(),
            code: 'CFO_A',
            name: 'A',
        );
        $projectB = new ProjectDirection(
            id: '22222222-2222-2222-2222-000000000102',
            company: $companyB,
            name: 'Project B',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Проект принадлежит другой компании.');
        new FinancialResponsibilityCenterProject(
            companyId: (string) $companyA->getId(),
            projectDirection: $projectB,
            responsibilityCenter: $centerA,
        );
    }

    public function testProjectPairRejectsCrossCompanyCenter(): void
    {
        $companyA = CompanyBuilder::aCompany()->withIndex(103)->build();
        $companyB = CompanyBuilder::aCompany()->withIndex(104)->build();
        $projectA = new ProjectDirection(
            id: '22222222-2222-2222-2222-000000000103',
            company: $companyA,
            name: 'Project A',
        );
        $centerB = new FinancialResponsibilityCenter(
            companyId: (string) $companyB->getId(),
            code: 'CFO_B',
            name: 'B',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ЦФО принадлежит другой компании.');
        new FinancialResponsibilityCenterProject(
            companyId: (string) $companyA->getId(),
            projectDirection: $projectA,
            responsibilityCenter: $centerB,
        );
    }

    public function testSystemProjectCannotBeDeleted(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(105)->build();
        $project = new ProjectDirection(
            id: '22222222-2222-2222-2222-000000000105',
            company: $company,
            name: 'Общий',
            systemCode: ProjectDirection::CODE_GENERAL,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Системный проект нельзя удалить.');
        $project->preventSystemProjectRemoval();
    }

    public function testRegularProjectCanBeDeleted(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(106)->build();
        $project = new ProjectDirection(
            id: '22222222-2222-2222-2222-000000000106',
            company: $company,
            name: 'Продажи',
        );

        $project->preventSystemProjectRemoval();
        self::addToAssertionCount(1);
    }
}
