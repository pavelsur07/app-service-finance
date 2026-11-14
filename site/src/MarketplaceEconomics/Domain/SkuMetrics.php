<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Domain;

/**
 * Показатели SKU (или кабинета) за период, копейки со знаком канона: выручка «+», затраты «−».
 * Уровни (`REPORTS.md` §1): валовая = выручка + себестоимость; МД1 = валовая + затраты МД1;
 * МД2 = МД1 + реклама; полная = МД2 + общие.
 */
final class SkuMetrics
{
    public int $salesQty = 0;
    public int $returnsQty = 0;
    public int $revenue = 0;
    public int $costPrice = 0;
    public int $commission = 0;
    public int $logisticsTo = 0;
    public int $logisticsBack = 0;
    public int $acquiring = 0;
    public int $otherMd1 = 0;
    public int $ads = 0;
    public int $overhead = 0;
    /** СПП и соинвестирование — справочно. */
    public int $spp = 0;

    public function add(string $measure, EconomicsLevel $level, int $amount, int $quantity): void
    {
        match ($level) {
            EconomicsLevel::REVENUE => $this->revenue += $amount,
            EconomicsLevel::COST_PRICE => $this->costPrice += $amount,
            EconomicsLevel::MD2 => $this->ads += $amount,
            EconomicsLevel::OVERHEAD => $this->overhead += $amount,
            EconomicsLevel::INFO => $this->spp += $amount,
            EconomicsLevel::MD1 => match (true) {
                str_starts_with($measure, 'commission') => $this->commission += $amount,
                str_starts_with($measure, 'logistics_to') => $this->logisticsTo += $amount,
                str_starts_with($measure, 'logistics_back') => $this->logisticsBack += $amount,
                'acquiring' === $measure => $this->acquiring += $amount,
                default => $this->otherMd1 += $amount,
            },
        };
        if ('sale' === $measure) {
            $this->salesQty += $quantity;
        } elseif ('return' === $measure) {
            $this->returnsQty += $quantity;
        }
    }

    public function merge(self $other): void
    {
        foreach (get_object_vars($other) as $field => $value) {
            $this->{$field} += $value;
        }
    }

    public function gross(): int
    {
        return $this->revenue + $this->costPrice;
    }

    public function md1(): int
    {
        return $this->gross() + $this->commission + $this->logisticsTo + $this->logisticsBack + $this->acquiring + $this->otherMd1;
    }

    public function md2(): int
    {
        return $this->md1() + $this->ads;
    }

    public function full(): int
    {
        return $this->md2() + $this->overhead;
    }

    /** Доля от выручки, %; нет выручки — null. */
    public function percentOfRevenue(int $amount): ?float
    {
        return $this->revenue > 0 ? round($amount * 100 / $this->revenue, 1) : null;
    }

    /** ДРР, % — реклама к выручке. */
    public function drr(): ?float
    {
        return $this->percentOfRevenue(-$this->ads);
    }

    /** ROI, % — полная прибыль к себестоимости. */
    public function roi(): ?float
    {
        return $this->costPrice < 0 ? round($this->full() * 100 / -$this->costPrice, 1) : null;
    }
}
