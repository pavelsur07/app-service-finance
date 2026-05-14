<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbLogisticsReturnCalculator implements CostCalculatorInterface
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
        return ($item['supplier_oper_name'] ?? '') === 'Логистика'
            && (int)($item['return_amount'] ?? 0) === 1;
    }

    public function requiresListing(): bool
    {
        return true;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $deliveryRub = (float)($item['delivery_rub'] ?? 0);

        if (abs($deliveryRub) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'logistics_return');
        if ($externalId === null) {
            return [];
        }

        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        return [
            [
                'category_code' => 'logistics_return',
                'amount' => (string)abs($deliveryRub),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Логистика возврат',
                'product' => $listing?->getProduct(),
            ],
        ];
    }
}
