<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbWarehouseLogisticsCalculator implements CostCalculatorInterface
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
        return ($item['supplier_oper_name'] ?? '') === 'Возмещение издержек по перевозке/по складским операциям с товаром';
    }

    public function requiresListing(): bool
    {
        // Не блокируем — listing опционален, решаем внутри calculate()
        return false;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $rebillLogisticCost = (float)($item['rebill_logistic_cost'] ?? 0);

        if (abs($rebillLogisticCost) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'warehouse_logistics');
        if ($externalId === null) {
            return [];
        }

        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        // Привязываем к товару только если listing найден (nm_id + sa_name были заполнены)
        $product = $listing?->getProduct();

        return [
            [
                'category_code' => 'warehouse_logistics',
                'amount' => (string)abs($rebillLogisticCost),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Логистика складские операции',
                'product' => $product, // null если нет привязки
            ],
        ];
    }
}
