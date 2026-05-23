<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbPenaltyCalculator implements CostCalculatorInterface
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
        return $this->normalizer->sellerOperName($item) === 'Штраф';
    }

    public function requiresListing(): bool
    {
        return false; // Не блокируем — listing опционален
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $penalty = (float)($item['penalty'] ?? 0);
        if (abs($penalty) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'penalty');
        if ($externalId === null) {
            return [];
        }

        $saleDate = $this->normalizer->operationDate($item);

        // Привязываем к товару только если listing найден
        $product = $listing?->getProduct();

        return [
            [
                'category_code' => 'penalty',
                'amount' => (string)abs($penalty),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Штраф WB',
                'product' => $product,
            ],
        ];
    }
}
