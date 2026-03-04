<?php

declare(strict_types=1);

namespace App\Finance\Facade;

use App\Enum\DocumentType;
use App\Finance\Application\Command\CreatePLDocumentCommand;
use App\Finance\Application\Command\CreatePLDocumentOperationCommand;
use App\Finance\Application\CreatePLDocumentAction;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\PLDocumentSource;
use App\Finance\Enum\PLDocumentStream;

/**
 * Публичный API модуля Finance для других модулей.
 *
 * Finance НЕ знает про маркетплейсы, таблицы Marketplace, маппинги.
 * Принимает универсальные скалярные данные: категория, сумма, период.
 *
 * Использует существующий CreatePLDocumentAction + CreatePLDocumentCommand.
 */
final class FinanceFacade
{
    public function __construct(
        private readonly CreatePLDocumentAction $createAction,
    ) {
    }

    /**
     * Создаёт документ ОПиУ с набором строк.
     *
     * @param string           $companyId  UUID компании
     * @param PLDocumentSource $source     Источник данных (WB, Ozon, manual...)
     * @param PLDocumentStream $stream     Поток (revenue, costs, storno)
     * @param string           $periodFrom Начало периода (Y-m-d)
     * @param string           $periodTo   Конец периода (Y-m-d)
     * @param array            $entries    Массив PLEntryDTO[] с полями:
     *                                     plCategoryId, projectId, amount, description, isNegative, sortOrder
     *
     * @return string documentId (UUID созданного документа)
     */
    public function createPLDocument(
        string $companyId,
        PLDocumentSource $source,
        PLDocumentStream $stream,
        string $periodFrom,
        string $periodTo,
        array $entries,
    ): string {
        $description = sprintf(
            '%s | %s | %s – %s',
            $source->getDisplayName(),
            $stream->getDisplayName(),
            $periodFrom,
            $periodTo,
        );

        // Трансформируем PLEntryDTO[] → CreatePLDocumentOperationCommand[]
        $operations = [];
        foreach ($entries as $entry) {
            $amount = $entry->amount;

            // Применяем знак: isNegative → инвертируем сумму
            if ($entry->isNegative) {
                $amount = bcmul($amount, '-1', 2);
            }

            $operations[] = new CreatePLDocumentOperationCommand(
                amount: $amount,
                categoryId: $entry->plCategoryId,
                counterpartyId: null,
                projectDirectionId: $entry->projectId,
                comment: $entry->description,
            );
        }

        $command = new CreatePLDocumentCommand(
            companyId: $companyId,
            date: new \DateTimeImmutable($periodTo),
            type: DocumentType::MARKETPLACE_PL,
            status: DocumentStatus::ACTIVE,
            number: null,
            description: $description,
            counterpartyId: null,
            projectDirectionId: null,
            operations: $operations,
            source: $source,          // ← НОВОЕ
            stream: $stream,          // ← НОВОЕ
        );

        return ($this->createAction)($command);
    }
}
