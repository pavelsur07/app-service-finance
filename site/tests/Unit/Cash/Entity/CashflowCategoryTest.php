<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Entity;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;

final class CashflowCategoryTest extends TestCase
{
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
}
