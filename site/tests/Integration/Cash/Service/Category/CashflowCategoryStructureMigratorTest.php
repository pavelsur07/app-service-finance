<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Category;

use App\Cash\Entity\Transaction\CashflowCategory;
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
        $legacyRoot = $this->createCategory('33333333-3333-4333-8333-333333333331', $company, 'Поступления (операционные)');
        $legacyChild = $this->createCategory('33333333-3333-4333-8333-333333333332', $company, 'Продажи', $legacyRoot);
        $legacyUnallocated = $this->createCategory('33333333-3333-4333-8333-333333333333', $company, 'Не распределено');
        $legacyUnallocated
            ->setCode(CashflowCategory::SYSTEM_UNALLOCATED)
            ->setIsSystem(true);
        $this->em->flush();

        $migrator = self::getContainer()->get(CashflowCategoryStructureMigrator::class);
        $plan = $migrator->plan((string) $company->getId());

        self::assertSame([], $plan['conflicts']);
        self::assertCount(6, array_filter($plan['categories'], static fn (array $category): bool => $category['create']));
        self::assertSame([$legacyRoot->getId()], $plan['rootsToMove']);

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
        self::assertSame(
            $byCode[CashflowCategory::CODE_TECHNICAL]['id'],
            $byCode[CashflowCategory::CODE_TECHNICAL_IN]['parent_id'],
        );
        self::assertSame(
            $byCode[CashflowCategory::CODE_TECHNICAL]['id'],
            $byCode[CashflowCategory::CODE_TECHNICAL_OUT]['parent_id'],
        );

        $movedRoot = $this->connection->fetchAssociative('SELECT parent_id, flow_kind FROM cashflow_categories WHERE id = :id', ['id' => $legacyRoot->getId()]);
        $movedChild = $this->connection->fetchAssociative('SELECT parent_id, flow_kind FROM cashflow_categories WHERE id = :id', ['id' => $legacyChild->getId()]);
        self::assertSame($byCode[CashflowCategory::CODE_OPERATING]['id'], $movedRoot['parent_id']);
        self::assertSame('OPERATING', $movedRoot['flow_kind']);
        self::assertSame($legacyRoot->getId(), $movedChild['parent_id']);
        self::assertSame('OPERATING', $movedChild['flow_kind']);

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

    private function createCategory(string $id, Company $company, string $name, ?CashflowCategory $parent = null): CashflowCategory
    {
        $category = (new CashflowCategory($id, $company))
            ->setName($name)
            ->setParent($parent);
        $this->em->persist($category);

        return $category;
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
