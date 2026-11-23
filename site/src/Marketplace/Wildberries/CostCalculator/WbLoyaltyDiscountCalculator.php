<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Wildberries\Infrastructure\Normalizer\WbSalesReportRowNormalizer;
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
        return 'Компенсация скидки по программе лояльности' === $this->normalizer->sellerOperName($item);
    }

    public function requiresListing(): bool
    {
        return false; // Не блокируем — listing опционален
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $cashbackDiscount = $this->normalizer->cashbackDiscount($item);

        if (abs($cashbackDiscount) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'wb_loyalty_discount_compensation');
        if (null === $externalId) {
            return [];
        }

        $saleDate = $this->normalizer->operationDate($item);

        return [
            [
                'category_code' => 'wb_loyalty_discount_compensation',
                'amount' => (string) abs($cashbackDiscount),
                // WB возвращает продавцу скидку покупателю — доход, как в финотчёте (addIncome)
                // и в каноне (compensation, +); отрицательная сумма — удержание обратно.
                'operation_type' => $cashbackDiscount > 0 ? MarketplaceCostOperationType::STORNO : MarketplaceCostOperationType::CHARGE,
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Компенсация скидки по программе лояльности WB',
                'product' => null, // товар процессоры берут из $listing, ключ не читается
            ],
        ];
    }
}
