<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;

class WbCommissionCalculator implements CostCalculatorInterface
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
        $retailPriceWithDisc = $this->normalizer->retailPriceWithDisc($item);
        $acquiringFee = $this->normalizer->acquiringFee($item);
        $forPay = $this->normalizer->forPay($item);
        $commission = $retailPriceWithDisc - $forPay - $acquiringFee;

        // Пропускаем нулевые комиссии
        if (abs($commission) < 0.01) {
            return [];
        }

        $srid = (string)$item['srid'];
        $saleDate = $this->normalizer->operationDate($item);
        $operationType = $this->normalizer->isReturn($item)
            ? MarketplaceCostOperationType::STORNO
            : MarketplaceCostOperationType::CHARGE;

        return [
            [
                'category_code' => 'commission',
                'amount' => (string)abs($commission),
                'external_id' => $srid . '_commission',
                'cost_date' => $saleDate,
                'description' => 'Комиссия маркетплейса',
                'operation_type' => $operationType,
                'product' => $listing?->getProduct(),
            ],
        ];
    }
}
