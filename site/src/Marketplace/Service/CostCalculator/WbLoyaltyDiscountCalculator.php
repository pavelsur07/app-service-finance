<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbLoyaltyDiscountCalculator implements CostCalculatorInterface
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
        return ($item['supplier_oper_name'] ?? '') === 'Компенсация скидки по программе лояльности';
    }

    public function requiresListing(): bool
    {
        return false; // Не блокируем — listing опционален
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $cashbackDiscount = (float)($item['cashback_discount'] ?? 0);

        if (abs($cashbackDiscount) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'wb_loyalty_discount_compensation');
        if ($externalId === null) {
            return [];
        }

        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        // Привязываем к товару только если listing найден
        $product = $listing?->getProduct();

        return [
            [
                'category_code' => 'wb_loyalty_discount_compensation',
                'amount' => (string)abs($cashbackDiscount),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Компенсация скидки по программе лояльности WB',
                'product' => $product,
            ],
        ];
    }
}
