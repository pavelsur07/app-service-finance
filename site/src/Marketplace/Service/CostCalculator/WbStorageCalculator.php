<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbStorageCalculator implements CostCalculatorInterface
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
        return ($item['supplier_oper_name'] ?? '') === 'Хранение';
    }

    public function requiresListing(): bool
    {
        return false;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $storageFee = (float)($item['storage_fee'] ?? 0);

        if (abs($storageFee) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'storage');
        if ($externalId === null) {
            return [];
        }

        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        return [
            [
                'category_code' => 'storage',
                'amount' => (string)abs($storageFee),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Хранение WB',
                'product' => null, // Нет привязки к товару
            ],
        ];
    }
}
