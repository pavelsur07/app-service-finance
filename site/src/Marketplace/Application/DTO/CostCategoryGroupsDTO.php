<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Аналитические группы категории затрат маркетплейса.
 *
 * `breakdownGroup` — у Ozon это `xlsxGroup` (группа отчёта Ozon), у WB —
 * `breakdownGroup`. `unitBucket` задан только у WB; для Ozon бакет выводит
 * потребитель, поэтому здесь `null`.
 */
final readonly class CostCategoryGroupsDTO
{
    public function __construct(
        public string $widgetGroup,
        public string $breakdownGroup,
        public ?string $unitBucket,
    ) {
    }
}
