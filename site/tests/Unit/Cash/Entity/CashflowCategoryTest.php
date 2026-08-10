<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Entity;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;

final class CashflowCategoryTest extends TestCase
{
    public function testCategoryCodeIsNormalized(): void
    {
        $category = new CashflowCategory(
            '11111111-1111-4111-8111-111111111111',
            CompanyBuilder::aCompany()->build(),
        );

        $category->setCode(' cf_custom_1 ');

        self::assertSame('CF_CUSTOM_1', $category->getCode());
        self::assertSame('CF_CUSTOM_1', $category->getSystemCode());
    }

    public function testSystemCategoryRejectsProtectedChanges(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = (new CashflowCategory('11111111-1111-4111-8111-111111111111', $company))
            ->setName('Операционная деятельность')
            ->setSort(10)
            ->markAsSystem(CashflowCategory::CODE_OPERATING);
        $newParent = (new CashflowCategory('22222222-2222-4222-8222-222222222222', $company))
            ->setName('Другой корень');

        foreach ([
            static fn () => $category->setName('Новое имя'),
            static fn () => $category->setCode('CF_OTHER'),
            static fn () => $category->setParent($newParent),
            static fn () => $category->setSort(20),
            static fn () => $category->setFlowKind(CashflowFlowKind::FINANCING),
            static fn () => $category->setIsSystem(false),
            static fn () => $category->assertCanDelete(),
        ] as $change) {
            try {
                $change();
                self::fail('Изменение системной категории должно быть запрещено.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSystemCategoryAllowsSameProtectedValues(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = (new CashflowCategory('11111111-1111-4111-8111-111111111111', $company))
            ->setName('Операционная деятельность')
            ->setSort(10)
            ->markAsSystem(CashflowCategory::CODE_OPERATING);

        $category
            ->setName('Операционная деятельность')
            ->setCode(CashflowCategory::CODE_OPERATING)
            ->setParent(null)
            ->setSort(10)
            ->setFlowKind(CashflowFlowKind::OPERATING)
            ->setIsSystem(true)
            ->setDescription('Разрешённое изменение');

        self::assertSame('Разрешённое изменение', $category->getDescription());
    }

    public function testRegularCategoryCannotUseReservedSystemCode(): void
    {
        $category = (new CashflowCategory(
            '11111111-1111-4111-8111-111111111111',
            CompanyBuilder::aCompany()->build(),
        ))->setName('Обычная категория');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('зарезервирован');

        $category->setCode(CashflowCategory::CODE_OPERATING);
    }

    public function testTechnicalFlowKindHasLabel(): void
    {
        self::assertSame('Технические операции', CashflowFlowKind::TECHNICAL->label());
    }

    public function testRootCategoryUsesOwnEffectiveFlowKind(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = new CashflowCategory('11111111-1111-4111-8111-111111111111', $company);
        $category->setFlowKind(CashflowFlowKind::FINANCING);

        self::assertTrue($category->isRoot());
        self::assertSame(CashflowFlowKind::FINANCING, $category->getEffectiveFlowKind());
    }

    public function testChildCategoryInheritsEffectiveFlowKindFromRoot(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        $root = new CashflowCategory('11111111-1111-4111-8111-111111111111', $company);
        $root->setFlowKind(CashflowFlowKind::INVESTING);

        $child = new CashflowCategory('22222222-2222-4222-8222-222222222222', $company);
        $child->setParent($root);
        $child->setFlowKind(CashflowFlowKind::OPERATING);

        self::assertFalse($child->isRoot());
        self::assertSame(CashflowFlowKind::INVESTING, $child->getEffectiveFlowKind());
    }

    public function testChildCategorySyncsStoredFlowKindFromParent(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        $root = new CashflowCategory('11111111-1111-4111-8111-111111111111', $company);
        $root->setFlowKind(CashflowFlowKind::TECHNICAL);

        $child = new CashflowCategory('22222222-2222-4222-8222-222222222222', $company);
        $child->setParent($root);
        $child->setFlowKind(CashflowFlowKind::OPERATING);

        $child->syncFlowKindWithParent();

        self::assertSame(CashflowFlowKind::TECHNICAL, $child->getFlowKind());
    }

    public function testRootCategorySyncsStoredFlowKindThroughDescendants(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        $root = new CashflowCategory('11111111-1111-4111-8111-111111111111', $company);
        $root->setFlowKind(CashflowFlowKind::TECHNICAL);

        $child = new CashflowCategory('22222222-2222-4222-8222-222222222222', $company);
        $child->setParent($root);
        $child->setFlowKind(CashflowFlowKind::OPERATING);

        $grandchild = new CashflowCategory('33333333-3333-4333-8333-333333333333', $company);
        $grandchild->setParent($child);
        $grandchild->setFlowKind(CashflowFlowKind::FINANCING);

        $root->syncFlowKindSubtree();

        self::assertSame(CashflowFlowKind::TECHNICAL, $root->getFlowKind());
        self::assertSame(CashflowFlowKind::TECHNICAL, $child->getFlowKind());
        self::assertSame(CashflowFlowKind::TECHNICAL, $grandchild->getFlowKind());
    }

    public function testMovedSubtreeSyncsStoredFlowKindFromNewRoot(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        $oldRoot = new CashflowCategory('11111111-1111-4111-8111-111111111111', $company);
        $oldRoot->setFlowKind(CashflowFlowKind::OPERATING);

        $newRoot = new CashflowCategory('22222222-2222-4222-8222-222222222222', $company);
        $newRoot->setFlowKind(CashflowFlowKind::FINANCING);

        $child = new CashflowCategory('33333333-3333-4333-8333-333333333333', $company);
        $child->setParent($oldRoot);
        $child->setFlowKind(CashflowFlowKind::OPERATING);

        $grandchild = new CashflowCategory('44444444-4444-4444-8444-444444444444', $company);
        $grandchild->setParent($child);
        $grandchild->setFlowKind(CashflowFlowKind::OPERATING);

        $child->setParent($newRoot);
        $child->syncFlowKindSubtree();

        self::assertSame(CashflowFlowKind::FINANCING, $child->getFlowKind());
        self::assertSame(CashflowFlowKind::FINANCING, $grandchild->getFlowKind());
    }

    public function testRejectsParentFromOwnSubtree(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $root = (new CashflowCategory('11111111-1111-4111-8111-111111111111', $company))->setName('Корень');
        $child = (new CashflowCategory('22222222-2222-4222-8222-222222222222', $company))
            ->setName('Дочерняя')
            ->setParent($root);
        $grandchild = (new CashflowCategory('33333333-3333-4333-8333-333333333333', $company))
            ->setName('Внучатая')
            ->setParent($child);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('текущую категорию или её потомка');

        $root->setParent($grandchild);
    }

    public function testRejectsDeletingCategoryWithChildren(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $parent = (new CashflowCategory('11111111-1111-4111-8111-111111111111', $company))->setName('Родитель');
        (new CashflowCategory('22222222-2222-4222-8222-222222222222', $company))
            ->setName('Дочерняя статья')
            ->setParent($parent);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('есть дочерние статьи');

        $parent->assertCanDelete();
    }

    public function testOnlyActivitySystemRootsAcceptRegularChildren(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $operating = (new CashflowCategory('11111111-1111-4111-8111-111111111111', $company))
            ->setName('Операционная деятельность')
            ->markAsSystem(CashflowCategory::CODE_OPERATING);
        $technical = (new CashflowCategory('22222222-2222-4222-8222-222222222222', $company))
            ->setName('Технические операции')
            ->setFlowKind(CashflowFlowKind::TECHNICAL)
            ->markAsSystem(CashflowCategory::CODE_TECHNICAL);

        self::assertTrue($operating->acceptsRegularChildren());
        self::assertFalse($technical->acceptsRegularChildren());

        $regularTechnical = (new CashflowCategory('33333333-3333-4333-8333-333333333333', $company))
            ->setName('Legacy technical root')
            ->setFlowKind(CashflowFlowKind::TECHNICAL);
        self::assertFalse($regularTechnical->acceptsRegularChildren());
    }
}
