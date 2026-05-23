<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbLogisticsDeliveryCalculator implements CostCalculatorInterface
{
    private WbSalesReportRowNormalizer $normalizer;
    private WbCostExternalIdBuilder $externalIdBuilder;

    public function __construct(?WbSalesReportRowNormalizer $normalizer = null, ?LoggerInterface $logger = null)
    {
        $this->normalizer = $normalizer ?? new WbSalesReportRowNormalizer();
        $this->externalIdBuilder = new WbCostExternalIdBuilder($this->normalizer, $logger ?? new NullLogger());
    }

    public function supports(array $item): bool
    {
        return $this->normalizer->sellerOperName($item) === 'Логистика'
            && (int) $this->normalizer->deliveryAmount($item) === 1;
    }

    public function requiresListing(): bool
    {
        return true;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $deliveryRub = $this->normalizer->deliveryService($item);

        if (abs($deliveryRub) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'logistics_delivery');
        if ($externalId === null) {
            return [];
        }

        $saleDate = $this->normalizer->operationDate($item);

        return [
            [
                'category_code' => 'logistics_delivery',
                'amount' => (string)abs($deliveryRub),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Логистика до покупателя',
                'product' => $listing?->getProduct(),
            ],
        ];
    }
}
