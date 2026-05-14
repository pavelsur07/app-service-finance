<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Калькулятор для затрат "Обработка товара"
 */
class WbProductProcessingCalculator implements CostCalculatorInterface
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
        return ($item['supplier_oper_name'] ?? '') === 'Обработка товара';
    }

    public function requiresListing(): bool
    {
        // Может быть как с товаром, так и без
        return false;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        // Сумма затраты - приёмка товара
        $amount = (float)($item['acceptance'] ?? 0);

        if (abs($amount) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'product_processing');
        if ($externalId === null) {
            return [];
        }

        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        // Проверяем есть ли nm_id и ts_name для привязки к товару
        $nmId = (string)($item['nm_id'] ?? '');
        $tsName = trim($item['ts_name'] ?? '');
        $product = null;

        // Если есть И nm_id И ts_name - привязываем к товару
        if ($nmId !== '' && $tsName !== '' && $listing) {
            $product = $listing->getProduct();
        }

        return [
            [
                'category_code' => 'product_processing',
                'category_name' => 'Обработка товара',
                'amount' => (string)abs($amount),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Обработка товара',
                'product' => $product, // Привязка к товару (если есть nm_id + ts_name)
            ],
        ];
    }
}
