<?php

declare(strict_types=1);

namespace App\Finance\Enum;

/**
 * Поток (stream) данных внутри документа ОПиУ.
 *
 * Один источник (source) за период создаёт отдельные документы по потокам:
 *   - REVENUE — продажи + возвраты (из MarketplaceSale / MarketplaceReturn)
 *   - COSTS   — комиссии, логистика, хранение (из MarketplaceCost)
 *   - STORNO  — корректировки/сторно (отложено)
 *
 * Это позволяет пересоздавать один поток без затрагивания других.
 */
enum PLDocumentStream: string
{
    case REVENUE = 'revenue';
    case COSTS = 'costs';
    case STORNO = 'storno';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::REVENUE => 'Выручка и возвраты',
            self::COSTS => 'Расходы маркетплейса',
            self::STORNO => 'Корректировки',
        };
    }
}
