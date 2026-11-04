<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Finance\Enum\PLCategoryType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Компания с владельцем, деревом ОПиУ (итог + два листа) и парами «проект × ЦФО»:
 * общая, вторая активная и пара с архивным ЦФО. Плюс чужая компания с листовой статьёй и парой.
 */
abstract class BudgetIntegrationTestCase extends IntegrationTestCase
{
    protected const COMPANY_ID = '0193cccc-0000-7000-8000-00000000c001';
    protected const OTHER_COMPANY_ID = '0193cccc-0000-7000-8000-00000000c002';
    protected const OWNER_ID = '0193cccc-0000-7000-8000-00000000a001';
    protected const MEMBER_ID = '0193cccc-0000-7000-8000-00000000a002';

    protected const SUBTOTAL_ID = '0193cccc-0000-7000-8000-00000000d001';
    protected const REVENUE_ID = '0193cccc-0000-7000-8000-00000000d002';
    protected const RENT_ID = '0193cccc-0000-7000-8000-00000000d003';
    protected const OTHER_COMPANY_LEAF_ID = '0193cccc-0000-7000-8000-00000000d009';

    protected const GENERAL_PROJECT_ID = '0193cccc-0000-7000-8000-00000000e001';
    protected const SHOP_PROJECT_ID = '0193cccc-0000-7000-8000-00000000e002';
    protected const OTHER_COMPANY_PROJECT_ID = '0193cccc-0000-7000-8000-00000000e009';

    protected string $generalCenterId;
    protected string $salesCenterId;
    protected string $archivedCenterId;
    protected string $otherCompanyCenterId;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()->withId(self::OWNER_ID)->withEmail('budget-owner@example.test')->build();
        $member = UserBuilder::aUser()->withId(self::MEMBER_ID)->withEmail('budget-member@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $this->persistAll($owner, $member, $company);

        $subtotal = PLCategoryBuilder::aPLCategory()->withId(self::SUBTOTAL_ID)->forCompany($company)->withName('Прибыль')->withType(PLCategoryType::SUBTOTAL)->build();
        $revenue = PLCategoryBuilder::aPLCategory()->withId(self::REVENUE_ID)->forCompany($company)->withName('Выручка')->withParent($subtotal)->build();
        $rent = PLCategoryBuilder::aPLCategory()->withId(self::RENT_ID)->forCompany($company)->withName('Аренда')->withParent($subtotal)->build();
        $this->persistAll($subtotal, $revenue, $rent);

        $general = new ProjectDirection(self::GENERAL_PROJECT_ID, $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $shop = new ProjectDirection(self::SHOP_PROJECT_ID, $company, 'Магазин');
        $generalCenter = new FinancialResponsibilityCenter(self::COMPANY_ID, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $salesCenter = new FinancialResponsibilityCenter(self::COMPANY_ID, 'SALES', 'Продажи');
        $archivedCenter = new FinancialResponsibilityCenter(self::COMPANY_ID, 'OLD', 'Старый');
        $archivedCenter->archive();
        $this->persistAll(
            $general, $shop, $generalCenter, $salesCenter, $archivedCenter,
            new FinancialResponsibilityCenterProject(self::COMPANY_ID, $general, $generalCenter),
            new FinancialResponsibilityCenterProject(self::COMPANY_ID, $shop, $salesCenter),
            new FinancialResponsibilityCenterProject(self::COMPANY_ID, $shop, $archivedCenter),
        );
        $this->generalCenterId = $generalCenter->getId();
        $this->salesCenterId = $salesCenter->getId();
        $this->archivedCenterId = $archivedCenter->getId();

        $otherOwner = UserBuilder::aUser()->withEmail('budget-other@example.test')->build();
        $otherCompany = CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($otherOwner)->build();
        $otherLeaf = PLCategoryBuilder::aPLCategory()->withId(self::OTHER_COMPANY_LEAF_ID)->forCompany($otherCompany)->build();
        $otherProject = new ProjectDirection(self::OTHER_COMPANY_PROJECT_ID, $otherCompany, 'Общий', ProjectDirection::CODE_GENERAL);
        $otherCenter = new FinancialResponsibilityCenter(self::OTHER_COMPANY_ID, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $this->persistAll($otherOwner, $otherCompany, $otherLeaf, $otherProject, $otherCenter, new FinancialResponsibilityCenterProject(self::OTHER_COMPANY_ID, $otherProject, $otherCenter));
        $this->otherCompanyCenterId = $otherCenter->getId();

        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @param list<mixed> $params
     */
    protected function countRows(string $table, string $where = 'TRUE', array $params = []): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $where), $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function lines(string $versionId): array
    {
        /* @var list<array<string, mixed>> */
        return $this->connection->fetchAllAssociative(
            'SELECT pl_category_id, month, project_direction_id, responsibility_center_id, amount, source, comment
               FROM budget_lines WHERE version_id = ? ORDER BY pl_category_id, month, project_direction_id, responsibility_center_id',
            [$versionId],
        );
    }

    private function persistAll(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
    }
}
