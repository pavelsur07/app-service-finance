<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Exception\CashflowCategoryInUseException;
use App\Cash\Infrastructure\Query\CashflowCategoryUsageQuery;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаляет статью ДДС, если её можно удалить: инварианты самой статьи — в сущности, ссылки
 * других записей (операции, платежи, правила) — здесь, до удаления.
 */
final class DeleteCashflowCategoryAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashflowCategoryUsageQuery $usageQuery,
    ) {
    }

    /**
     * @throws \DomainException статью удалить нельзя (системная, есть дочерние)
     * @throws CashflowCategoryInUseException на статью ссылаются другие записи
     */
    public function __invoke(CashflowCategory $category): void
    {
        $category->assertCanDelete();

        $categoryId = (string) $category->getId();
        $usage = ($this->usageQuery)((string) $category->getCompany()->getId(), $categoryId)[$categoryId] ?? null;
        if (null !== $usage && $usage->isUsed()) {
            throw CashflowCategoryInUseException::withUsage($usage);
        }

        $this->entityManager->remove($category);
        try {
            $this->entityManager->flush();
        } catch (ForeignKeyConstraintViolationException $exception) {
            // Ссылку добавили между проверкой и удалением; flush уже откатил транзакцию.
            throw CashflowCategoryInUseException::referenced($exception);
        }
    }
}
