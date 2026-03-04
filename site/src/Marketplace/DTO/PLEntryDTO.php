<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

/**
 * DTO для одной строки документа ОПиУ.
 *
 * Все поля scalar — worker-safe, сериализуемый.
 * Передаётся из Marketplace в FinanceFacade::createPLDocument().
 */
final class PLEntryDTO
{
    public function __construct(
        /** UUID категории ОПиУ из модуля Finance */
        public readonly string $plCategoryId,

        /** UUID проекта/направления (nullable) */
        public readonly ?string $projectId,

        /** Сумма (decimal как string для точности bcmath) — всегда положительная */
        public readonly string $amount,

        /** Дата периода (Y-m-d) */
        public readonly string $periodDate,

        /** Описание строки: "Выручка с СПП — WB" */
        public readonly string $description,

        /** true → сумма инвертируется (расходы, возвраты) */
        public readonly bool $isNegative,

        /** Порядок сортировки в документе */
        public readonly int $sortOrder = 0,
    ) {
    }
}
