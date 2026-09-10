<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Категория услуги Ozon как тип контракта OzonAccrualCategoryFacade.
 *
 * Проекция внутренней OzonAccrualCategory: наружу отдаются только поля,
 * нужные вызывающему модулю для классификации затрат и для очереди ручного
 * разбора. TransactionType сознательно не выносится — это внутренняя семантика
 * Ingestion, и потребителю в Marketplace она не нужна.
 */
final readonly class OzonAccrualCategoryView
{
    public function __construct(
        /** Стабильный snake_case-код категории, напр. `ozon_logistics`. */
        public string $code,
        /** Человекочитаемое название для интерфейса и отчётов. */
        public string $label,
        /** Группа категории; у неразобранных — «Требует классификации». */
        public string $group,
        /** Порядок в отчётах; у неразобранных заведомо больше любого известного. */
        public int $sortOrder,
        /** false означает «услуга не разобрана» и требует ручного сопоставления. */
        public bool $known,
        /** Идентификатор услуги из ответа Ozon, как пришёл. */
        public ?string $typeId,
        /** Имя услуги из справочника /v1/finance/accrual/types, как пришло. */
        public ?string $typeName,
    ) {
    }
}
