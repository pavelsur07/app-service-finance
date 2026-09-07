<?php

declare(strict_types=1);

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WbWarehouseLogisticsCalculator implements CostCalculatorInterface
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
            'Возмещение издержек по перевозке/по складским операциям с товаром' => true,
            'Возмещение издержек по перемещению и операционной обработке товара' => abs($this->normalizer->rebillLogisticCost($item)) >= self::MIN_AMOUNT,
            default => false,
        };
    }

    public function requiresListing(): bool
    {
        // Не блокируем — listing опционален, решаем внутри calculate()
        return false;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $rebillLogisticCost = $this->normalizer->rebillLogisticCost($item);

        if (abs($rebillLogisticCost) < self::MIN_AMOUNT) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, 'warehouse_logistics');
        if (null === $externalId) {
            return [];
        }

        $saleDate = $this->normalizer->operationDate($item);

        // Привязываем к товару только если listing найден (nm_id + sa_name были заполнены)
        $product = $listing?->getProduct();

        return [
            [
                'category_code' => 'warehouse_logistics',
                'amount' => (string) abs($rebillLogisticCost),
                'external_id' => $externalId,
                'cost_date' => $saleDate,
                'description' => 'Логистика складские операции',
                'product' => $product, // null если нет привязки
            ],
        ];
    }
}
