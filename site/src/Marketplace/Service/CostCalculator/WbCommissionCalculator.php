<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbCommissionCalculator implements CostCalculatorInterface
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
        $commission = $this->normalizer->commissionAmount($item);

        // Пропускаем строки без цены (formула неприменима) и нулевые/отрицательные
        // комиссии. ponytail: отрицательная комиссия (кВВ% < 0) не встречается в данных
        // (0 из 5618 строк на PROD); появится — добавить обратную операцию.
        if ($this->normalizer->grossWithoutSpp($item) < 0.01 || $commission < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'commission');
        if (null === $externalId) {
            return [];
        }

        $saleDate = $this->normalizer->operationDate($item);
        $operationType = $this->normalizer->isReturn($item)
            ? MarketplaceCostOperationType::STORNO
            : MarketplaceCostOperationType::CHARGE;

        return [
            [
                'category_code' => 'commission',
                'amount' => (string) abs($commission),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Комиссия маркетплейса',
                'operation_type' => $operationType,
                'product' => $listing?->getProduct(),
            ],
        ];
    }
}
