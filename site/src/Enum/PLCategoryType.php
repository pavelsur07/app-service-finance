<?php

declare(strict_types=1);

namespace App\Enum;

enum PLCategoryType: string
{
    case LEAF_INPUT = 'LEAF_INPUT'; // лист, данные из фактов/агрегатов
    case SUBTOTAL = 'SUBTOTAL';   // итоговая строка (subtotal)
    case KPI = 'KPI';        // расчётный показатель (формула)
}
