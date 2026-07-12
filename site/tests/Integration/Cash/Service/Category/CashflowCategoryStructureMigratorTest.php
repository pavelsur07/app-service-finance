<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Category;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Service\Category\CashflowCategoryStructureMigrator;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class CashflowCategoryStructureMigratorTest extends IntegrationTestCase
{
    public function testMigratesLegacyTreeAndIsIdempotent(): void
    {
        $company = $this->persistCompany();
        $operatingRoot = $this->createCategory('33333333-3333-4333-8333-333333333331', $company, 'Поступления (операционные)');
        $operatingChild = $this->createCategory('33333333-3333-4333-8333-333333333332', $company, 'Продажи', $operatingRoot);
        $financingRoot = $this->createCategory('33333333-3333-4333-8333-333333333333', $company, 'Кредитные операции', null, CashflowFlowKind::FINANCING);
        $financingChild = $this->createCategory('33333333-3333-4333-8333-333333333334', $company, 'Получение кредита', $financingRoot, CashflowFlowKind::FINANCING);
        $investingRoot = $this->createCategory('33333333-3333-4333-8333-333333333335', $company, 'Инвестиционные проекты', null, CashflowFlowKind::INVESTING);
        $technicalRoot = $this->createCategory('33333333-3333-4333-8333-333333333336', $company, 'Служебные операции', null, CashflowFlowKind::TECHNICAL);
        $canonicalFinancing = $this->createCategory('33333333-3333-4333-8333-333333333337', $company, 'Финансовая деятельность');
        $canonicalFinancingChild = $this->createCategory('33333333-3333-4333-8333-333333333338', $company, 'Финансовая категория', $canonicalFinancing);
        $legacyUnallocated = $this->createCategory('33333333-3333-4333-8333-333333333339', $company, 'Не распределено');
        $legacyUnallocated
            ->setCode(CashflowCategory::SYSTEM_UNALLOCATED)
            ->setIsSystem(true);
        $this->em->flush();

        $migrator = self::getContainer()->get(CashflowCategoryStructureMigrator::class);
        $plan = $migrator->plan((string) $company->getId());

        self::assertSame([], $plan['conflicts']);
        self::assertCount(5, array_filter($plan['categories'], static fn (array $category): bool => $category['create']));
        self::assertSame([
            ['id' => $operatingRoot->getId(), 'flowKind' => CashflowFlowKind::OPERATING->value],
            ['id' => $financingRoot->getId(), 'flowKind' => CashflowFlowKind::FINANCING->value],
            ['id' => $investingRoot->getId(), 'flowKind' => CashflowFlowKind::INVESTING->value],
            ['id' => $technicalRoot->getId(), 'flowKind' => CashflowFlowKind::TECHNICAL->value],
        ], $plan['rootsToMove']);

        $migrator->execute($plan);
        $this->em->clear();

        $categories = $this->connection->fetchAllAssociative(
            'SELECT id, parent_id, name, system_code, flow_kind, is_system FROM cashflow_categories WHERE company_id = :companyId',
            ['companyId' => $company->getId()],
        );
        $byCode = [];
        foreach ($categories as $category) {
            if (null !== $category['system_code']) {
                $byCode[$category['system_code']] = $category;
            }
        }

        self::assertCount(7, array_intersect(array_keys($byCode), $this->systemCodes()));
        self::assertSame($legacyUnallocated->getId(), $byCode[CashflowCategory::CODE_UNALLOCATED]['id']);
        self::assertSame($canonicalFinancing->getId(), $byCode[CashflowCategory::CODE_FINANCING]['id']);
        self::assertSame(
            $byCode[CashflowCategory::CODE_TECHNICAL]['id'],
            $byCode[CashflowCategory::CODE_TECHNICAL_IN]['parent_id'],
        );
        self::assertSame(
            $byCode[CashflowCategory::CODE_TECHNICAL]['id'],
            $byCode[CashflowCategory::CODE_TECHNICAL_OUT]['parent_id'],
        );

        $this->assertMovedTree($operatingRoot, $operatingChild, $byCode[CashflowCategory::CODE_OPERATING]['id'], CashflowFlowKind::OPERATING);
        $this->assertMovedTree($financingRoot, $financingChild, $byCode[CashflowCategory::CODE_FINANCING]['id'], CashflowFlowKind::FINANCING);
        $this->assertMovedTree($investingRoot, null, $byCode[CashflowCategory::CODE_INVESTING]['id'], CashflowFlowKind::INVESTING);
        $this->assertMovedTree($technicalRoot, null, $byCode[CashflowCategory::CODE_TECHNICAL]['id'], CashflowFlowKind::TECHNICAL);

        $canonicalChild = $this->connection->fetchAssociative(
            'SELECT parent_id, flow_kind FROM cashflow_categories WHERE id = :id',
            ['id' => $canonicalFinancingChild->getId()],
        );
        self::assertSame($canonicalFinancing->getId(), $canonicalChild['parent_id']);
        self::assertSame(CashflowFlowKind::FINANCING->value, $canonicalChild['flow_kind']);

        $secondPlan = $migrator->plan((string) $company->getId());
        self::assertSame([], $secondPlan['conflicts']);
        self::assertSame([], $secondPlan['rootsToMove']);
        self::assertCount(0, array_filter($secondPlan['categories'], static fn (array $category): bool => $category['create']));
    }

    public function testReportsAmbiguousRootNamesWithoutChangingData(): void
    {
        $company = $this->persistCompany();
        $this->createCategory('33333333-3333-4333-8333-333333333341', $company, 'Операционная деятельность');
        $this->createCategory('33333333-3333-4333-8333-333333333342', $company, 'Операционная деятельность');
        $this->em->flush();

        $plan = self::getContainer()->get(CashflowCategoryStructureMigrator::class)->plan((string) $company->getId());

        self::assertNotEmpty($plan['conflicts']);
        self::assertStringContainsString(CashflowCategory::CODE_OPERATING, $plan['conflicts'][0]);
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cashflow_categories WHERE company_id = :companyId',
            ['companyId' => $company->getId()],
        ));
    }

    private function persistCompany(): Company
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);

        return $company;
    }

    private function createCategory(
        string $id,
        Company $company,
        string $name,
        ?CashflowCategory $parent = null,
        CashflowFlowKind $flowKind = CashflowFlowKind::OPERATING,
    ): CashflowCategory {
        $category = (new CashflowCategory($id, $company))
            ->setName($name)
            ->setParent($parent)
            ->setFlowKind($flowKind);
        $this->em->persist($category);

        return $category;
    }

    private function assertMovedTree(
        CashflowCategory $root,
        ?CashflowCategory $child,
        string $expectedParentId,
        CashflowFlowKind $flowKind,
    ): void {
        $movedRoot = $this->connection->fetchAssociative(
            'SELECT parent_id, flow_kind FROM cashflow_categories WHERE id = :id',
            ['id' => $root->getId()],
        );
        self::assertSame($expectedParentId, $movedRoot['parent_id']);
        self::assertSame($flowKind->value, $movedRoot['flow_kind']);

        if (null === $child) {
            return;
        }

        $movedChild = $this->connection->fetchAssociative(
            'SELECT parent_id, flow_kind FROM cashflow_categories WHERE id = :id',
            ['id' => $child->getId()],
        );
        self::assertSame($root->getId(), $movedChild['parent_id']);
        self::assertSame($flowKind->value, $movedChild['flow_kind']);
    }

    /** @return list<string> */
    private function systemCodes(): array
    {
        return [
            CashflowCategory::CODE_OPERATING,
            CashflowCategory::CODE_FINANCING,
            CashflowCategory::CODE_INVESTING,
            CashflowCategory::CODE_TECHNICAL,
            CashflowCategory::CODE_TECHNICAL_IN,
            CashflowCategory::CODE_TECHNICAL_OUT,
            CashflowCategory::CODE_UNALLOCATED,
        ];
    }
}
