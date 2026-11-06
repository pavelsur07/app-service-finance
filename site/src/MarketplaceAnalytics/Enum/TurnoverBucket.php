<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Enum;

/**
 * Корзина оборачиваемости по дням запаса (остаток ÷ средние продажи в день).
 * Границы: < 30 → Fast; 30 ≤ d < 60 → Medium; 60 ≤ d ≤ 90 → Slow; > 90 → Frozen;
 * продаж за период нет → NoSales. «Заморожено» — Frozen + NoSales.
 */
enum TurnoverBucket: string
{
    case Fast = 'fast';
    case Medium = 'medium';
    case Slow = 'slow';
    case Frozen = 'frozen';
    case NoSales = 'no_sales';

    public static function fromDaysOfCover(?int $days): self
    {
        return match (true) {
            null === $days => self::NoSales,
            $days < 30 => self::Fast,
            $days < 60 => self::Medium,
            $days <= 90 => self::Slow,
            default => self::Frozen,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Fast => 'до 30 дней',
            self::Medium => '30–60 дней',
            self::Slow => '60–90 дней',
            self::Frozen => 'свыше 90 дней',
            self::NoSales => 'нет продаж',
        };
    }

    public function isFrozen(): bool
    {
        return self::Frozen === $this || self::NoSales === $this;
    }
}
