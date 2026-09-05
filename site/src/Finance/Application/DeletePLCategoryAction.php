<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Entity\PLCategory;
use App\Finance\Exception\PLCategoryInUseException;
use App\Finance\Repository\DocumentOperationRepository;
use App\Finance\Repository\PLDailyTotalRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаляет статью ОПиУ и переносит её агрегаты `pl_daily_totals` в «Без категории».
 *
 * Операции документов (`document_operations`) в статью не мигрируют: это реальные
 * пользовательские записи, а не производный агрегат, поэтому их категория не
 * переносится молча. Удаление отклоняется, пока такие операции существуют — владелец
 * данных должен сам решить, куда их перекатегоризировать.
 *
 * Принадлежность категории компании проверяет вызывающий контроллер.
 */
final class DeletePLCategoryAction
{
    // Postgres складывает непроцитированные идентификаторы в нижний регистр, поэтому
    // это то же имя, что видно в тексте ошибки FK (SQLSTATE 23503).
    private const DOCUMENT_OPERATION_FK_CONSTRAINT = 'fk_doc_oper_category';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PLDailyTotalRepository $dailyTotalRepository,
        private readonly DocumentOperationRepository $documentOperationRepository,
    ) {
    }

    /**
     * @throws PLCategoryInUseException если к статье привязаны операции документов ОПиУ
     */
    public function __invoke(PLCategory $category, string $companyId): void
    {
        $categoryId = (string) $category->getId();

        if ($this->documentOperationRepository->countByCategory($companyId, $categoryId) > 0) {
            throw new PLCategoryInUseException($categoryId);
        }

        try {
            $this->em->wrapInTransaction(function () use ($category, $companyId, $categoryId): void {
                $this->dailyTotalRepository->moveCategoryRowsToUncategorized($companyId, $categoryId);
                $this->em->remove($category);
            });
        } catch (ForeignKeyConstraintViolationException $exception) {
            // На pl_categories ссылаются и другие таблицы без ON DELETE (cashflow_categories,
            // wildberries_report_detail_mappings, finance_loan). Их FK-нарушения — это не
            // "привязаны операции документов", а отдельный дефект; транслируем только тот
            // constraint, который эта Action действительно проверяет и умеет объяснить
            // пользователю. Остальные пробрасываем как есть, а не маскируем под этот случай.
            if (!str_contains($exception->getMessage(), self::DOCUMENT_OPERATION_FK_CONSTRAINT)) {
                throw $exception;
            }

            throw new PLCategoryInUseException($categoryId, $exception);
        }
    }
}
