<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

use App\Budget\Enum\BudgetFillHelper;

/**
 * Запрос помощника заполнения. Пара обязательна; `months` (`Y-m`) пуст — все месяцы года
 * (у `distribute` и `copy_right` месяцы задаёт сам помощник). `params`: `months` (3/6/12) —
 * average; `amount` — distribute; `sourceMonth` — copy_right; `percent`, `baseCategoryId` —
 * percent_of.
 */
final readonly class BudgetFillRequest
{
    /**
     * @param list<string> $categoryIds
     * @param list<string> $months
     * @param array<string, mixed> $params
     */
    public function __construct(
        public BudgetFillHelper $helper,
        public string $projectDirectionId,
        public string $responsibilityCenterId,
        public array $categoryIds,
        public array $months,
        public array $params,
    ) {
    }
}
