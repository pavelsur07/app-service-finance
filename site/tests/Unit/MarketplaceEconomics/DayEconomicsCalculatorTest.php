<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceEconomics;

use App\MarketplaceEconomics\Domain\DayEconomics;
use App\MarketplaceEconomics\Domain\DayEconomicsCalculator as Calc;
use App\MarketplaceEconomics\Domain\EconomicsLevel;
use App\MarketplaceOperations\Application\DTO\OperationRecord;
use App\MarketplaceOperations\Enum\OperationKind as K;
use PHPUnit\Framework\TestCase;

final class DayEconomicsCalculatorTest extends TestCase
{
    private const A = '0192f0a0-0000-7000-8000-00000000000a';
    private const B = '0192f0a0-0000-7000-8000-00000000000b';

    public function testLevelsCostPriceAndInvariant(): void
    {
        $day = $this->calc([
            $this->op(self::A, K::SALE, 300000, 3),
            $this->op(self::A, K::RETURN, -100000, 1),
            $this->op(self::A, K::SPP, 20000),
            $this->op(self::A, K::COMMISSION, -30000),
            $this->op(self::A, K::PROMO_STARS, -5000),
            $this->op(self::B, K::SALE, 100000, 1),
            $this->op(null, K::SUBSCRIPTION, -9000),
        ], [self::A => '400.50']);

        // канон без справочного СПП
        self::assertSame(300000 - 100000 - 30000 - 5000 + 100000 - 9000, $day->canonMinor);
        // A: продано 3 − 1 = 2 шт. × 400,50; у B цены нет
        self::assertSame([-80100, 1], [$day->costPriceMinor, $day->withoutCostPrice]);
        self::assertSame(['revenue' => 200000, 'cost_price' => -80100, 'md1' => -30000, 'md2' => -5000, 'overhead' => -6000, 'info' => 20000], $this->byLevel($day, self::A));
        // подписка по выручке 200 000 : 100 000
        self::assertSame(-3000, $this->sum($day, self::B, EconomicsLevel::OVERHEAD));
        self::assertSame(0, $day->unallocatedMinor);
        $this->assertBalanced($day);
    }

    public function testAdsByLegacySharesOfOwnKindStorageByStockAndRounding(): void
    {
        $day = $this->calc([
            $this->op(self::A, K::SALE, 100000, 1),
            $this->op(null, K::ADS_CPC, -1000),
            $this->op(null, K::ADS_PAY_PER_ORDER, -500),
            $this->op(null, K::STORAGE, -100),
        ], [], ['ads_cpc' => [self::A => '1.00', self::B => '2.00', 'gone' => '0'], 'ads_pay_per_order' => [self::B => '5.00']], [self::A => 1.0, self::B => 2.0]);

        // клики 1000 по 1 : 2 → 333 / 667 (наибольший остаток); оплата за заказ 500 — только B; хранение 100 по остатку 1 : 2 → 33 / 67
        self::assertSame([-333, -1167], [$this->sum($day, self::A, EconomicsLevel::MD2), $this->sum($day, self::B, EconomicsLevel::MD2)]);
        self::assertSame('legacy_order_share', $this->lineByKey($day, self::B, 'legacy_order_share')?->allocationKey);
        self::assertSame([-33, -67], [$this->sum($day, self::A, EconomicsLevel::OVERHEAD), $this->sum($day, self::B, EconomicsLevel::OVERHEAD)]);
        self::assertSame(Calc::KEY_STOCK, $this->line($day, self::B, Calc::STORAGE_SHARE)?->allocationKey);
        self::assertSame(0, $day->unallocatedMinor);
        $this->assertBalanced($day);
    }

    public function testWithoutWeightsEverythingStaysUnallocated(): void
    {
        // нет выручки, нет остатков, нет статистики оплаты за заказ в легаси: хранение уходит к общим, а им некуда
        $day = $this->calc([
            $this->op(self::A, K::RETURN, -5000, 1),
            $this->op(null, K::STORAGE, -700),
            $this->op(null, K::ADS_PAY_PER_ORDER, -300),
            $this->op(null, K::SALE, 2000, 1),
        ], [], ['ads_cpc' => [self::A => '9.00']]);

        self::assertSame(-700 - 300 + 2000, $day->unallocatedMinor);
        self::assertNull($this->line($day, self::A, Calc::OVERHEAD_SHARE));
        $this->assertBalanced($day);
    }

    public function testDirectCostWithoutSkuIsAllocatedByRevenue(): void
    {
        $day = $this->calc([
            $this->op(self::A, K::SALE, 100000, 1),
            $this->op(self::B, K::SALE, 100000, 1),
            $this->op(null, K::LOGISTICS_TO, -1001),
        ]);

        self::assertSame([-501, -500], [$this->sum($day, self::A, EconomicsLevel::OVERHEAD), $this->sum($day, self::B, EconomicsLevel::OVERHEAD)]);
        self::assertSame(Calc::KEY_REVENUE, $this->line($day, self::A, Calc::OVERHEAD_SHARE)?->allocationKey);
        $this->assertBalanced($day);
    }

    public function testForeignCurrencyFailsTheDay(): void
    {
        $this->expectException(\DomainException::class);
        (new Calc())->calculate([new OperationRecord(self::A, K::SALE, 'ozon:field:sale_amount', 100, 'KZT', 1)], [], [], []);
    }

    /**
     * @param list<OperationRecord> $ops
     * @param array<string, string> $prices
     * @param array<string, array<string, string>> $ads
     * @param array<string, float> $stock
     */
    private function calc(array $ops, array $prices = [], array $ads = [], array $stock = []): DayEconomics
    {
        return (new Calc())->calculate($ops, $prices, $ads, $stock);
    }

    private function op(?string $listing, K $kind, int $amount, ?int $qty = null): OperationRecord
    {
        return new OperationRecord($listing, $kind, 'test:'.$kind->value, $amount, 'RUB', $qty);
    }

    private function assertBalanced(DayEconomics $day): void
    {
        $sum = 0;
        foreach ($day->lines as $line) {
            if (!\in_array($line->level, [EconomicsLevel::COST_PRICE, EconomicsLevel::INFO], true)) {
                $sum += $line->amountMinor;
            }
        }
        self::assertSame($day->canonMinor, $sum, 'Σ строк витрины = Σ канона');
    }

    /** @return array<string, int> */
    private function byLevel(DayEconomics $day, string $listing): array
    {
        $sums = [];
        foreach (EconomicsLevel::cases() as $level) {
            $sums[$level->value] = $this->sum($day, $listing, $level);
        }

        return $sums;
    }

    private function sum(DayEconomics $day, string $listing, EconomicsLevel $level): int
    {
        $sum = 0;
        foreach ($day->lines as $line) {
            if ($line->listingId === $listing && $line->level === $level) {
                $sum += $line->amountMinor;
            }
        }

        return $sum;
    }

    private function lineByKey(DayEconomics $day, string $listing, string $key): ?\App\MarketplaceEconomics\Domain\EconomicsLine
    {
        foreach ($day->lines as $line) {
            if ($line->listingId === $listing && $line->allocationKey === $key) {
                return $line;
            }
        }

        return null;
    }

    private function line(DayEconomics $day, string $listing, string $measure): ?\App\MarketplaceEconomics\Domain\EconomicsLine
    {
        foreach ($day->lines as $line) {
            if ($line->listingId === $listing && $line->measure === $measure) {
                return $line;
            }
        }

        return null;
    }
}
