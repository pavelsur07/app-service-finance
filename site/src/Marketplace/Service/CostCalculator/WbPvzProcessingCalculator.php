<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbPvzProcessingCalculator implements CostCalculatorInterface
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
        return $this->normalizer->sellerOperName($item) === 'Возмещение за выдачу и возврат товаров на ПВЗ';
    }

    public function requiresListing(): bool
    {
        return false;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $ppvzReward = $this->normalizer->ppvzReward($item);

        if (abs($ppvzReward) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'pvz_processing');
        if ($externalId === null) {
            return [];
        }

        $saleDate = $this->normalizer->operationDate($item);

        return [
            [
                'category_code' => 'pvz_processing',
                'amount' => (string)abs($ppvzReward),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Логистика обработка на ПВЗ',
            ],
        ];
    }
}
