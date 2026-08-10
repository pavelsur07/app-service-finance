<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\SaveCashflowCategoryAction;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class SaveCashflowCategoryActionTest extends IntegrationTestCase
{
    public function testDetachingPersistedCategoryKeepsItInsteadOfDeletingIt(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $parent = (new CashflowCategory('11111111-1111-4111-8111-111111111111', $company))
            ->setName('Инвестиционная деятельность')
            ->setFlowKind(CashflowFlowKind::INVESTING);
        $child = (new CashflowCategory('22222222-2222-4222-8222-222222222222', $company))
            ->setName('Дочерняя статья')
            ->setParent($parent)
            ->syncFlowKindWithParent();

        foreach ([$user, $company, $parent, $child] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $persistedChild = $this->em->find(CashflowCategory::class, $child->getId());
        self::assertNotNull($persistedChild);

        $persistedChild->setParent(null);
        self::getContainer()->get(SaveCashflowCategoryAction::class)($persistedChild);
        $this->em->clear();

        $detached = $this->connection->fetchAssociative(
            'SELECT parent_id, flow_kind FROM cashflow_categories WHERE id = :id',
            ['id' => $child->getId()],
        );

        self::assertIsArray($detached);
        self::assertNull($detached['parent_id']);
        self::assertSame(CashflowFlowKind::INVESTING->value, $detached['flow_kind']);
    }

    public function testRemovingPersistedCategoryWithChildrenIsRejectedBeforeDatabaseDelete(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $parent = (new CashflowCategory('33333333-3333-4333-8333-333333333333', $company))
            ->setName('Родитель');
        $child = (new CashflowCategory('44444444-4444-4444-8444-444444444444', $company))
            ->setName('Дочерняя статья')
            ->setParent($parent);

        foreach ([$user, $company, $parent, $child] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        try {
            $this->em->remove($parent);
            $this->em->flush();
            self::fail('Категория с дочерними статьями не должна удаляться.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('есть дочерние статьи', $exception->getMessage());
        }

        $this->em->clear();
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cashflow_categories WHERE id IN (:parentId, :childId)',
            ['parentId' => $parent->getId(), 'childId' => $child->getId()],
        ));
    }
}
