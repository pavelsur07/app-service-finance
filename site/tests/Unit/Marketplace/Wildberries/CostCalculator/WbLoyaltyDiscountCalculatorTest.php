<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Wildberries\CostCalculator;

use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Wildberries\CostCalculator\WbLoyaltyDiscountCalculator;
use PHPUnit\Framework\TestCase;

/**
 * «Компенсация скидки по программе лояльности» — WB возвращает продавцу скидку покупателю:
 * доход (сторно затраты), как в финотчёте (`addIncome`) и в каноне (`compensation`, +).
 * Регрессия 23.11: строка шла начислением и попадала в ОПиУ расходом.
 */
final class WbLoyaltyDiscountCalculatorTest extends TestCase
{
    public function testPositiveCompensationIsStorno(): void
    {
        $entries = (new WbLoyaltyDiscountCalculator())->calculate($this->row(100.0), null);

        self::assertCount(1, $entries);
        self::assertSame('100', $entries[0]['amount']);
        self::assertSame(MarketplaceCostOperationType::STORNO, $entries[0]['operation_type'] ?? null);
    }

    public function testNegativeCompensationIsCharge(): void
    {
        $entries = (new WbLoyaltyDiscountCalculator())->calculate($this->row(-40.0), null);

        self::assertCount(1, $entries);
        self::assertSame('40', $entries[0]['amount']);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $entries[0]['operation_type'] ?? null);
    }

    /** @return array<string, mixed> */
    private function row(float $amount): array
    {
        return [
            'sellerOperName' => 'Компенсация скидки по программе лояльности',
            'rrdId' => 3137360497746,
            'saleDt' => '2026-10-28T16:02:26Z',
            'cashbackDiscount' => $amount,
        ];
    }
}
