<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceFunnel;

use App\MarketplaceFunnel\Domain\OzonFunnelTranslator;
use App\MarketplaceFunnel\Domain\WbFunnelTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FunnelTranslatorsTest extends TestCase
{
    public function testOzonRowMapsMetricsByRequestOrder(): void
    {
        // порядок ozon.FunnelMetrics: показы поиск/карточка/всего, сессии ×3, корзины поиск/карточка/всего,
        // заказано, выручка, возвраты, отмены, доставлено
        $row = OzonFunnelTranslator::translate(['dimensions' => [['id' => '11', 'name' => 'Товар']], 'metrics' => [70, 30, 100.0, 7, 3, 9, 4, 2, 6, 5, 1234.5, 1, 2, 3]]);

        self::assertSame('11', $row->sku);
        self::assertSame([
            'impressions' => 100, 'impressions_search' => 70, 'card_views' => 30, 'cart_adds' => 6, 'orders' => 5,
            'revenue_minor' => 123450, 'buyouts' => 3, 'cancels' => 2, 'returns' => 1,
        ], $row->values);
        self::assertSame(100 + 70 + 30 + 6 + 5 + 123450 + 3 + 2 + 1, $row->controlSum());
    }

    /** Живой ответ 18.11.2026: кабинету без тарифа Ozon отдаёт только заказы и выручку — две метрики вместо 14. */
    public function testOzonRowWithoutSubscriptionHasOnlyOrdersAndRevenue(): void
    {
        $row = OzonFunnelTranslator::translate(['dimensions' => [['id' => '11', 'name' => 'Товар']], 'metrics' => [5, 1234.5]]);

        self::assertSame(5, $row->values['orders']);
        self::assertSame(123450, $row->values['revenue_minor']);
        self::assertSame([], array_filter($row->values, static fn (?int $v, string $k): bool => null !== $v && !\in_array($k, ['orders', 'revenue_minor'], true), \ARRAY_FILTER_USE_BOTH), 'остальные ступени — «нет данных», не ноль');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('brokenOzonRows')]
    public function testOzonRowOfAnotherShapeIsRejected(array $payload): void
    {
        $this->expectException(\DomainException::class);
        OzonFunnelTranslator::translate($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function brokenOzonRows(): iterable
    {
        yield 'нет SKU' => [['dimensions' => [], 'metrics' => array_fill(0, 14, 0)]];
        yield 'другое число метрик' => [['dimensions' => [['id' => '11']], 'metrics' => [1, 2, 3]]];
        yield 'дробный счётчик' => [['dimensions' => [['id' => '11']], 'metrics' => array_merge([5.4], array_fill(0, 13, 0))]];
        yield 'метрика не число' => [['dimensions' => [['id' => '11']], 'metrics' => array_merge(array_fill(0, 13, 0), ['x'])]];
    }

    public function testWbCardHasNoImpressionsAndNoReturns(): void
    {
        $row = WbFunnelTranslator::translate(['product' => ['nmId' => 7, 'vendorCode' => 'a'], 'statistic' => ['selected' => [
            'openCount' => 40, 'cartCount' => 8, 'orderCount' => 3, 'orderSum' => 4500, 'buyoutCount' => 2, 'cancelCount' => 1,
        ]]]);

        self::assertSame('7', $row->sku);
        self::assertSame([
            'impressions' => null, 'impressions_search' => null, 'card_views' => 40, 'cart_adds' => 8, 'orders' => 3,
            'revenue_minor' => 450000, 'buyouts' => 2, 'cancels' => 1, 'returns' => null,
        ], $row->values, 'нет ступени — null, а не ноль');
    }

    public function testWbStageWithoutFieldIsNoDataNotFailure(): void
    {
        $row = WbFunnelTranslator::translate(['product' => ['nmId' => 7], 'statistic' => ['selected' => ['openCount' => 4, 'cancelCount' => null]]]);

        self::assertSame(4, $row->values['card_views']);
        self::assertNull($row->values['cancels']);
        self::assertNull($row->values['orders']);
    }

    public function testWbCardWithoutStatisticIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        WbFunnelTranslator::translate(['product' => ['nmId' => 7]]);
    }
}
