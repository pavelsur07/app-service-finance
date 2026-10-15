<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Transaction\CashflowCategory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаляет статью ДДС, если её можно удалить (инвариант — в сущности).
 */
final class DeleteCashflowCategoryAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @throws \DomainException статью удалить нельзя
     */
    public function __invoke(CashflowCategory $category): void
    {
        $category->assertCanDelete();
        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }
}
