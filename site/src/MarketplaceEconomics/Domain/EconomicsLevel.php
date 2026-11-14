<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Domain;

use App\MarketplaceOperations\Enum\CascadeLevel;

/**
 * Уровень строки витрины (`REPORTS.md` §1): выручка → валовая (себестоимость) → МД1 → МД2 → полная
 * (общие). `info` (СПП, соинвестирование) — справочно, в итоги не входит.
 */
enum EconomicsLevel: string
{
    case REVENUE = 'revenue';
    case COST_PRICE = 'cost_price';
    case MD1 = 'md1';
    case MD2 = 'md2';
    case OVERHEAD = 'overhead';
    case INFO = 'info';

    public static function fromCascade(CascadeLevel $level): self
    {
        return match ($level) {
            CascadeLevel::REVENUE => self::REVENUE,
            CascadeLevel::MD1 => self::MD1,
            CascadeLevel::MD2 => self::MD2,
            CascadeLevel::OVERHEAD => self::OVERHEAD,
            CascadeLevel::INFO => self::INFO,
        };
    }
}
