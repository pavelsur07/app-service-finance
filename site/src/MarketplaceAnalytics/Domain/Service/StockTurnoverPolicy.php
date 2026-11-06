<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\Service;

use App\MarketplaceAnalytics\DTO\TurnoverMeasure;
use App\MarketplaceAnalytics\Enum\TurnoverBucket;
use Webmozart\Assert\Assert;

/**
 * Дней запаса = остаток ÷ (продано за период ÷ дней в периоде). Считается
 * целочисленно как intdiv(остаток × дней, продано), чтобы граница ровно в 30/60/90
 * дней не зависела от float-округления средней скорости.
 */
final readonly class StockTurnoverPolicy
{
    public function measure(int $stockQty, int $salesQty, int $periodDays): TurnoverMeasure
    {
        Assert::greaterThan($periodDays, 0, 'Period must be positive, got %s');

        if ($salesQty <= 0) {
            return new TurnoverMeasure(0.0, null, TurnoverBucket::NoSales);
        }

        $daysOfCover = intdiv(max(0, $stockQty) * $periodDays, $salesQty);

        return new TurnoverMeasure(
            round($salesQty / $periodDays, 2),
            $daysOfCover,
            TurnoverBucket::fromDaysOfCover($daysOfCover),
        );
    }
}
