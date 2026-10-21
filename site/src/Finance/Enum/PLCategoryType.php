<?php

declare(strict_types=1);

namespace App\Finance\Enum;

enum PLCategoryType: string
{
    case LEAF_INPUT = 'LEAF_INPUT'; // лист, данные из фактов/агрегатов
    case SUBTOTAL = 'SUBTOTAL';   // итоговая строка (subtotal)
    case KPI = 'KPI';        // расчётный показатель (формула)

    /**
     * Суммирует ли статья своих потомков: только подытог без формулы (PlReportCalculator).
     * Строка ввода берёт свои факты, KPI и подытог с формулой — формулу.
     */
    public function sumsChildren(?string $formula): bool
    {
        return self::SUBTOTAL === $this && '' === trim((string) $formula);
    }
}
