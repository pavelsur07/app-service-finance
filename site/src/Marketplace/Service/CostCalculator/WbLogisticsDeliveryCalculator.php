<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbLogisticsDeliveryCalculator implements CostCalculatorInterface
{
    private const float MIN_AMOUNT = 0.01;

    private WbSalesReportRowNormalizer $normalizer;
    private WbCostExternalIdBuilder $externalIdBuilder;

    public function __construct(?WbSalesReportRowNormalizer $normalizer = null, ?LoggerInterface $logger = null)
    {
        $this->normalizer = $normalizer ?? new WbSalesReportRowNormalizer();
        $this->externalIdBuilder = new WbCostExternalIdBuilder($this->normalizer, $logger ?? new NullLogger());
    }

    public function supports(array $item): bool
    {
        // New delivery rows omit the legacy counter. Return signals keep the row
        // visible as unsupported until their financial mapping is confirmed.
        return match ($this->normalizer->sellerOperName($item)) {
            'Логистика' => 1 === (int) $this->normalizer->deliveryAmount($item),
            'Доставка' => 0 === (int) $this->normalizer->returnAmount($item)
                && !$this->normalizer->isReturn($item)
                && abs($this->normalizer->deliveryService($item)) >= self::MIN_AMOUNT,
            default => false,
        };
    }

    public function requiresListing(): bool
    {
        return true;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $deliveryRub = $this->normalizer->deliveryService($item);

        if (abs($deliveryRub) < self::MIN_AMOUNT) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'logistics_delivery');
        if (null === $externalId) {
            return [];
        }

        $saleDate = $this->normalizer->operationDate($item);

        return [
            [
                'category_code' => 'logistics_delivery',
                'amount' => (string) abs($deliveryRub),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Логистика до покупателя',
                'product' => $listing?->getProduct(),
            ],
        ];
    }
}
