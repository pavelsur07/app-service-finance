<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Wildberries\Infrastructure\Normalizer\WbSalesReportRowNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Калькулятор для «Сумма баллов, удержанных в рамках акции "Баллы за отзывы"».
 *
 * С октября 2026 WB удерживает баллы, выданные покупателям за отзывы, отдельной
 * строкой отчёта: сумма только в `cashbackAmount` (строкой), `forPay` и `deduction`
 * нулевые. Положительная сумма — удержание, отрицательная — его сторно.
 * Удержание внутри маркетплейса, по сути — реклама/маркетинг.
 */
class WbReviewPointsWithheldCalculator implements CostCalculatorInterface
{
    private const string CATEGORY_CODE = 'wb_review_points_withheld';
    private const string OPERATION_NAME = 'сумма баллов, удержанных в рамках акции "баллы за отзывы"';

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
        $name = str_replace(['«', '»', '„', '“', '”'], '"', $this->normalizer->sellerOperName($item));
        $name = preg_replace('/\s+/u', ' ', mb_strtolower(trim($name))) ?? $name;

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
        $cashbackAmount = $this->normalizer->cashbackAmount($item);

        if (abs($cashbackAmount) < 0.01) {
            return [];
        }

        $externalId = $this->externalIdBuilder->build($item, self::CATEGORY_CODE);
        if (null === $externalId) {
            return [];
        }

        return [
            [
                'category_code' => self::CATEGORY_CODE,
                'category_name' => 'Баллы за отзывы (удержание)',
                'amount' => (string) abs($cashbackAmount),
                'operation_type' => $cashbackAmount < 0
                    ? MarketplaceCostOperationType::STORNO
                    : MarketplaceCostOperationType::CHARGE,
                'external_id' => $externalId,
                'cost_date' => $this->normalizer->operationDate($item),
                'description' => 'Баллы за отзывы: удержание внутри маркетплейса (по сути реклама/маркетинг)',
                'product' => null, // товар процессоры берут из $listing, ключ не читается
            ],
        ];
    }
}
