<?php

declare(strict_types=1);

namespace App\Cash\Application\Category;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Exception\CashflowCategoryInUseException;
use App\Cash\Infrastructure\Query\CashflowCategoryUsageQuery;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
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
        private readonly CashflowCategoryRepository $categories,
    ) {
    }

    /**
     * @throws \DomainException статью удалить нельзя (системная, есть дочерние, базового дерева)
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

        try {
            $this->entityManager->wrapInTransaction(function () use ($category): void {
                // Код консолидации могли проставить после загрузки статьи: проверка по строке под FOR UPDATE.
                $stored = $this->categories->lockStoredPlacement((string) $category->getCompany()->getId(), (string) $category->getId());
                if (null !== $stored && null !== $stored['groupCode']) {
                    throw new \DomainException('Статью базового дерева нельзя удалить: по ней строится консолидированная отчётность. Её можно переименовать.');
                }

                $this->entityManager->remove($category);
                $this->entityManager->flush();
            });
        } catch (ForeignKeyConstraintViolationException $exception) {
            // Ссылку добавили между проверкой и удалением; flush уже откатил транзакцию.
            throw CashflowCategoryInUseException::referenced($exception);
        }
    }
}
