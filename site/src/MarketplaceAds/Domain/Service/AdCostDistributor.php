<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Domain\Service;

use App\MarketplaceAds\Application\DTO\CostDistributionResult;

/**
 * Чистая функция распределения рекламных затрат по листингам.
 * Данные о продажах передаются снаружи — это позволяет caller'у выполнить один bulk-запрос
 * для всех листингов сразу и избежать N+1 при обработке большого количества кампаний.
 */
final readonly class AdCostDistributor
{
    private const WEIGHT_SCALE = 10;

    /**
     * Распределить рекламные затраты по листингам пропорционально продажам.
     * При отсутствии продаж — равномерное распределение.
     * Контроль округления: разница прибавляется к строке с наибольшей долей.
     *
     * @param array{id: string, parentSku: string}[] $listings
     * @param array<string, int> $salesByListing listingId => quantity (отсутствующие = 0)
     *
     * @return CostDistributionResult[]
     */
    public function distribute(
        array $listings,
        array $salesByListing,
        string $totalCost,
        int $totalImpressions,
        int $totalClicks,
    ): array {
        if (empty($listings)) {
            return [];
        }

        $count = count($listings);

        // Нормализуем продажи: листинги без записи получают 0.
        $sales = [];
        foreach ($listings as $listing) {
            $sales[$listing['id']] = $salesByListing[$listing['id']] ?? 0;
        }

        $totalSales = (int) array_sum($sales);

        // Шаг 1: Вычисляем веса с высокой точностью
        if ($totalSales > 0) {
            $weights = [];
            foreach ($listings as $listing) {
                $weights[$listing['id']] = bcdiv(
                    (string) $sales[$listing['id']],
                    (string) $totalSales,
                    self::WEIGHT_SCALE,
                );
            }
        } else {
            // Равномерное распределение
            $evenWeight = bcdiv('1', (string) $count, self::WEIGHT_SCALE);
            $weights = [];
            foreach ($listings as $listing) {
                $weights[$listing['id']] = $evenWeight;
            }
        }

        // Шаг 2: Найти листинг с наибольшим весом для поправки округления
        $maxWeightId = array_key_first($weights);
        foreach ($weights as $id => $w) {
            if (bccomp($w, $weights[$maxWeightId], self::WEIGHT_SCALE) > 0) {
                $maxWeightId = $id;
            }
        }

        // Шаг 3: Вычислить значения по каждому листингу (с усечением до нужной точности)
        $results = [];
        $sumCost = '0.00';
        $sumShare = '0.00';
        $sumImpressions = 0;
        $sumClicks = 0;

        foreach ($listings as $listing) {
            $id = $listing['id'];
            $w = $weights[$id];

            $cost = bcmul($totalCost, $w, 2);
            $sharePercent = bcmul($w, '100', 2);
            $impressions = (int) bcmul((string) $totalImpressions, $w, 0);
            $clicks = (int) bcmul((string) $totalClicks, $w, 0);

            $sumCost = bcadd($sumCost, $cost, 2);
            $sumShare = bcadd($sumShare, $sharePercent, 2);
            $sumImpressions += $impressions;
            $sumClicks += $clicks;

            $results[$id] = new CostDistributionResult(
                listingId: $id,
                sharePercent: $sharePercent,
                cost: $cost,
                impressions: $impressions,
                clicks: $clicks,
            );
        }

        // Шаг 4: Поправка округления — добавляем разницу к строке с наибольшей долей
        $maxItem = $results[$maxWeightId];

        $results[$maxWeightId] = new CostDistributionResult(
            listingId: $maxItem->listingId,
            sharePercent: bcadd($maxItem->sharePercent, bcsub('100.00', $sumShare, 2), 2),
            cost: bcadd($maxItem->cost, bcsub($totalCost, $sumCost, 2), 2),
            impressions: $maxItem->impressions + ($totalImpressions - $sumImpressions),
            clicks: $maxItem->clicks + ($totalClicks - $sumClicks),
        );

        return array_values($results);
    }
}
