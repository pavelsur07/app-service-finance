<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Entity\PLCategory;
use App\Finance\Exception\PLCategoryInUseException;
use App\Finance\Infrastructure\Query\PLCategoryUsageQuery;
use App\Finance\Repository\PLDailyTotalRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаляет статью ОПиУ и переносит её агрегаты `pl_daily_totals` в «Без категории».
 *
 * Вложенные статьи и пользовательские записи, ссылающиеся на статью (операции документов,
 * статьи ДДС, кредиты, правила разнесения продаж маркетплейсов), молча не переносятся:
 * удаление отклоняется с перечнем причин — куда их деть, решает владелец данных.
 *
 * Принадлежность категории компании проверяет вызывающий контроллер.
 */
final class DeletePLCategoryAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PLDailyTotalRepository $dailyTotalRepository,
        private readonly PLCategoryUsageQuery $usageQuery,
    ) {
    }

    /**
     * @throws PLCategoryInUseException у статьи есть вложенные или на неё ссылаются другие записи
     */
    public function __invoke(PLCategory $category, string $companyId): void
    {
        $categoryId = (string) $category->getId();

        $usage = ($this->usageQuery)($companyId, $categoryId)[$categoryId] ?? null;
        if (null !== $usage && $usage->isUsed()) {
            throw PLCategoryInUseException::withUsage($usage);
        }

        try {
            $this->em->wrapInTransaction(function () use ($category, $companyId, $categoryId): void {
                $this->dailyTotalRepository->moveCategoryRowsToUncategorized($companyId, $categoryId);
                $this->em->remove($category);
            });
        } catch (ForeignKeyConstraintViolationException $exception) {
            // Ссылку добавили между проверкой и удалением; транзакция уже откачена.
            throw PLCategoryInUseException::referenced($exception);
        }
    }
}
