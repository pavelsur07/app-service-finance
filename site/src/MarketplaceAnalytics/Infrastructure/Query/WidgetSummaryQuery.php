<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Application\Service\WidgetServiceGroupMap;

/**
 * Сводка для виджета MarketplaceAnalytics за период.
 *
 * Повторяет агрегацию UnitExtendedQuery, но без per-listing detail:
 * возвращает только итоговые числа и разбивку затрат по widgetGroup
 * (5 групп WidgetServiceGroupMap).
 */
final readonly class WidgetSummaryQuery
{
    private const FALLBACK_GROUP = 'Другие услуги и штрафы';

    /** @var list<string> */
    private const WIDGET_GROUPS = [
        'Вознаграждение',
        'Услуги доставки и FBO',
        'Услуги партнёров',
        'Продвижение и реклама',
        'Другие услуги и штрафы',
    ];

    public function __construct(
        private MarketplaceFacade $marketplaceFacade,
    ) {
    }

    /**
     * @return array{
     *     revenue: float,
     *     returnsTotal: float,
     *     costPriceTotal: float,
     *     totalCosts: float,
     *     profit: float,
     *     marginPercent: float|null,
     *     widgetGroups: list<array<string, mixed>>,
     * }
     */
    public function getSummary(
        string $companyId,
        ?string $marketplace,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): array {
        $sales = $this->marketplaceFacade->getSalesAggregatesByListing($companyId, $marketplace, $dateFrom, $dateTo);
        $returns = $this->marketplaceFacade->getReturnAggregatesByListing($companyId, $marketplace, $dateFrom, $dateTo);
        $costs = $this->marketplaceFacade->getCostAggregatesByListing($companyId, $marketplace, $dateFrom, $dateTo);

        $codeToGroup = WidgetServiceGroupMap::getCategoryToWidgetGroup();

        $revenue = 0.0;
        $returnsTotal = 0.0;
        $costPriceTotal = 0.0;

        $groups = [];
        foreach (self::WIDGET_GROUPS as $groupName) {
            $groups[$groupName] = [
                'serviceGroup' => $groupName,
                'costsAmount'  => 0.0,
                'stornoAmount' => 0.0,
                'netAmount'    => 0.0,
                'categories'   => [],
            ];
        }

        // Merge all unique listing IDs from three sources so listings
        // with only returns or costs are not lost
        $allListingIds = array_unique(array_merge(
            array_keys($sales),
            array_keys($returns),
            array_keys($costs),
        ));

        foreach ($allListingIds as $listingId) {
            $sale = $sales[$listingId] ?? null;
            $ret = $returns[$listingId] ?? null;
            $listingCosts = $costs[$listingId] ?? [];

            if ($sale !== null) {
                $revenue += (float) $sale->revenue;
                $costPriceTotal += (float) $sale->costPriceTotal;
            }

            if ($ret !== null) {
                $returnsTotal += (float) $ret->returnsTotal;
            }

            foreach ($listingCosts as $cat) {
                $group = $codeToGroup[$cat->categoryCode] ?? self::FALLBACK_GROUP;

                $costsAmt = (float) $cat->costsAmount;
                $stornoAmt = (float) $cat->stornoAmount;
                $netAmt = (float) $cat->netAmount;

                $groups[$group]['costsAmount'] += $costsAmt;
                $groups[$group]['stornoAmount'] += $stornoAmt;
                $groups[$group]['netAmount'] += $netAmt;

                // Агрегируем одинаковые categoryCode внутри группы
                $code = $cat->categoryCode;
                if (!isset($groups[$group]['categories'][$code])) {
                    $groups[$group]['categories'][$code] = [
                        'code'         => $code,
                        'name'         => $cat->categoryName,
                        'costsAmount'  => 0.0,
                        'stornoAmount' => 0.0,
                        'netAmount'    => 0.0,
                    ];
                }

                $groups[$group]['categories'][$code]['costsAmount'] += $costsAmt;
                $groups[$group]['categories'][$code]['stornoAmount'] += $stornoAmt;
                $groups[$group]['categories'][$code]['netAmount'] += $netAmt;
            }
        }

        // Build widgetGroups list with rounding and sorting
        $widgetGroups = [];
        $totalCosts = 0.0;
        foreach ($groups as $group) {
            $categories = [];
            foreach ($group['categories'] as $cat) {
                $categories[] = [
                    'code'         => $cat['code'],
                    'name'         => $cat['name'],
                    'costsAmount'  => round($cat['costsAmount'], 2),
                    'stornoAmount' => round($cat['stornoAmount'], 2),
                    'netAmount'    => round($cat['netAmount'], 2),
                ];
            }

            // Sort categories inside group by costsAmount DESC
            usort($categories, static fn (array $a, array $b): int => $b['costsAmount'] <=> $a['costsAmount']);

            $netAmount = round($group['netAmount'], 2);
            $totalCosts += $netAmount;

            $widgetGroups[] = [
                'serviceGroup' => $group['serviceGroup'],
                'costsAmount'  => round($group['costsAmount'], 2),
                'stornoAmount' => round($group['stornoAmount'], 2),
                'netAmount'    => $netAmount,
                'categories'   => $categories,
            ];
        }

        // Sort widgetGroups by netAmount DESC
        usort($widgetGroups, static fn (array $a, array $b): int => $b['netAmount'] <=> $a['netAmount']);

        $totalCosts = round($totalCosts, 2);
        $revenue = round($revenue, 2);
        $returnsTotal = round($returnsTotal, 2);
        $costPriceTotal = round($costPriceTotal, 2);
        $profit = round($revenue - $returnsTotal - $costPriceTotal - $totalCosts, 2);

        $marginPercent = $revenue > 0 ? round($profit / $revenue * 100, 1) : null;

        return [
            'revenue'        => $revenue,
            'returnsTotal'   => $returnsTotal,
            'costPriceTotal' => $costPriceTotal,
            'totalCosts'     => $totalCosts,
            'profit'         => $profit,
            'marginPercent'  => $marginPercent,
            'widgetGroups'   => $widgetGroups,
        ];
    }
}
