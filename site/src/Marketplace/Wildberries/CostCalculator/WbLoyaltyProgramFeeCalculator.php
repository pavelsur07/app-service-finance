<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Wildberries\Infrastructure\Normalizer\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Калькулятор для «Стоимость участия в программе лояльности».
 *
 * С 28.10.2026 WB берёт комиссию за участие в программе лояльности отдельной
 * строкой отчёта (`bonusTypeName` — «Комиссия ВБ за участие в программе
 * лояльности "Баллы за отзывы"»): сумма только в `cashbackCommissionChange`
 * (строкой), `forPay` и `deduction` нулевые. Положительная сумма — списание,
 * отрицательная — его сторно, как у удержания баллов за отзывы.
 */
class WbLoyaltyProgramFeeCalculator implements CostCalculatorInterface
{
    private const string CATEGORY_CODE = 'wb_loyalty_program_fee';
    private const string OPERATION_NAME = 'стоимость участия в программе лояльности';

    private WbSalesReportRowNormalizer $normalizer;
    private WbCostExternalIdBuilder $externalIdBuilder;

    public function __construct(?WbSalesReportRowNormalizer $normalizer = null, ?LoggerInterface $logger = null)
    {
        $this->normalizer = $normalizer ?? new WbSalesReportRowNormalizer();
        $this->externalIdBuilder = new WbCostExternalIdBuilder($this->normalizer, $logger ?? new NullLogger());
    }

    /**
     * @param array<string, mixed> $item
     */
    public function supports(array $item): bool
    {
        $name = preg_replace('/\s+/u', ' ', mb_strtolower(trim($this->normalizer->sellerOperName($item))));

        return self::OPERATION_NAME === $name;
    }

    public function requiresListing(): bool
    {
        return false; // Не блокируем — listing опционален
    }

    /**
     * @param array<string, mixed> $item
     */
    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $fee = $this->normalizer->cashbackCommissionChange($item);

        if (abs($fee) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, self::CATEGORY_CODE);
        if (null === $externalId) {
            return [];
        }

        return [
            [
                'category_code' => self::CATEGORY_CODE,
                'category_name' => 'Стоимость участия в программе лояльности',
                'amount' => (string) abs($fee),
                'operation_type' => $fee < 0
                    ? MarketplaceCostOperationType::STORNO
                    : MarketplaceCostOperationType::CHARGE,
                'external_id' => $externalId,
                'cost_date' => $this->normalizer->operationDate($item),
                'description' => 'Комиссия WB за участие в программе лояльности',
                'product' => null, // товар процессоры берут из $listing, ключ не читается
            ],
        ];
    }
}
