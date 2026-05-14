<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;

class WbAcquiringCalculator implements CostCalculatorInterface
{
    private WbSalesReportRowNormalizer $normalizer;

    public function __construct(?WbSalesReportRowNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new WbSalesReportRowNormalizer();
    }

    public function supports(array $item): bool
    {
        return $this->normalizer->isSaleOrReturn($item);
    }

    public function requiresListing(): bool
    {
        return true;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $acquiringFee = $this->normalizer->acquiringFee($item);

        if (abs($acquiringFee) < 0.01) {
            return [];
        }

        $srid = (string)$item['srid'];
        $saleDate = $this->normalizer->operationDate($item);
        $operationType = $this->normalizer->isReturn($item)
            ? MarketplaceCostOperationType::STORNO
            : MarketplaceCostOperationType::CHARGE;

        return [
            [
                'category_code' => 'acquiring',
                'amount' => (string)abs($acquiringFee),
                'external_id' => $srid . '_acquiring',
                'cost_date' => $saleDate,
                'description' => 'Эквайринг',
                'operation_type' => $operationType,
                'product' => $listing?->getProduct(),
            ],
        ];
    }
}
