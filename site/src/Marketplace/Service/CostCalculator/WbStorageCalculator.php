<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbStorageCalculator implements CostCalculatorInterface
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
        // Preserve the legacy operation contract. A newly observed alias is claimed
        // only when its documented amount field is present, so schema drift remains visible.
        return match ($this->normalizer->sellerOperName($item)) {
            'Хранение' => true,
            'Коррекция хранения' => abs($this->normalizer->paidStorage($item)) >= self::MIN_AMOUNT,
            default => false,
        };
    }

    public function requiresListing(): bool
    {
        return false;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $storageFee = $this->normalizer->paidStorage($item);

        if (abs($storageFee) < self::MIN_AMOUNT) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'storage');
        if (null === $externalId) {
            return [];
        }

        $saleDate = $this->normalizer->operationDate($item);

        return [
            [
                'category_code' => 'storage',
                'amount' => (string) abs($storageFee),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Хранение WB',
                'product' => null, // Нет привязки к товару
            ],
        ];
    }
}
