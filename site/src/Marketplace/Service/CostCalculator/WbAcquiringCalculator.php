<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbAcquiringCalculator implements CostCalculatorInterface
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

        $externalId = $this->externalIdBuilder->build($item, 'acquiring');
        if ($externalId === null) {
            return [];
        }

        $saleDate = $this->normalizer->operationDate($item);
        $operationType = $this->normalizer->isReturn($item)
            ? MarketplaceCostOperationType::STORNO
            : MarketplaceCostOperationType::CHARGE;

        return [
            [
                'category_code' => 'acquiring',
                'amount' => (string)abs($acquiringFee),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Эквайринг',
                'operation_type' => $operationType,
                'product' => $listing?->getProduct(),
            ],
        ];
    }
}
