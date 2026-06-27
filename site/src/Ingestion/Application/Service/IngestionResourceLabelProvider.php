<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;

final class IngestionResourceLabelProvider
{
    /**
     * @var array<string, array{group: string, label: string}>
     */
    private const LABELS = [
        OzonResourceType::ACCRUAL_BY_DAY => [
            'group' => 'Ozon Finance',
            'label' => 'Accrual by day',
        ],
        OzonResourceType::ACCRUAL_TYPES => [
            'group' => 'Ozon Finance',
            'label' => 'Accrual types',
        ],
        OzonResourceType::ACCRUAL_POSTINGS => [
            'group' => 'Ozon Finance',
            'label' => 'Accrual postings',
        ],
        OzonResourceType::PERFORMANCE_CAMPAIGNS => [
            'group' => 'Ozon Performance / Campaigns',
            'label' => 'Campaign catalog',
        ],
        OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS => [
            'group' => 'Ozon Performance / SKU CPC',
            'label' => 'Campaign SKU objects',
        ],
        OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS => [
            'group' => 'Ozon Performance / Search Promo CPO',
            'label' => 'Search Promo products',
        ],
        OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS => [
            'group' => 'Ozon Performance / SKU CPC',
            'label' => 'SKU product statistics',
        ],
        OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS => [
            'group' => 'Ozon Performance / Search Promo CPO',
            'label' => 'Search Promo statistics',
        ],
        OzonResourceType::PERFORMANCE_EXPENSE_STATISTICS => [
            'group' => 'Ozon Performance / Expense control',
            'label' => 'Expense statistics',
        ],
        WbResourceType::FINANCE_SALES_REPORT_DETAILED => [
            'group' => 'Wildberries Finance',
            'label' => 'Sales report detailed',
        ],
    ];

    /**
     * @return array{group: string, label: string}
     */
    public function describe(string $resourceType): array
    {
        return self::LABELS[$resourceType] ?? [
            'group' => 'Ingestion',
            'label' => $resourceType,
        ];
    }
}
