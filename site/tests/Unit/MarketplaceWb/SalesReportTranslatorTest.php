<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceWb;

use App\MarketplaceOperations\Enum\CascadeLevel;
use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceWb\Domain\SalesReportTranslator;
use App\MarketplaceWb\Domain\WbComponent;
use App\MarketplaceWb\Domain\WbSourceTypeSeed;
use PHPUnit\Framework\TestCase;

final class SalesReportTranslatorTest extends TestCase
{
    public function testSaleSplitsIntoRevenueCommissionAcquiringAndSppAndAddsUpToForPay(): void
    {
        $components = $this->translate($this->sale());

        self::assertSame([
            'wb:field:sale' => [200000, 'sale'],
            'wb:calc:spp' => [70000, 'spp'],
            'wb:field:acquiringFee' => [-3000, 'acquiring'],
            'wb:calc:commission' => [-47000, 'commission'],
        ], $this->summary($components));
        self::assertSame(150000, $this->total($components), 'итог строки = forPay');
        self::assertSame(['123', '42', 2], [$components[0]->nmId, $components[0]->size, $components[0]->quantity]);
    }

    public function testReturnHasOppositeSigns(): void
    {
        $components = $this->translate(['docTypeName' => 'Возврат', 'sellerOperName' => 'Возврат'] + $this->sale());

        self::assertSame([200000, -47000 * -1, 3000], [-$this->amount($components, 'wb:field:return'), $this->amount($components, 'wb:calc:commission'), $this->amount($components, 'wb:field:acquiringFee')]);
        self::assertSame(OperationKind::RETURN, $components[0]->kind);
        self::assertSame(-150000, $this->total($components));
    }

    public function testAcquiringCorrectionGivesNoFalseCommission(): void
    {
        $components = $this->translate($this->row(['docTypeName' => 'Продажа', 'sellerOperName' => 'Корректировка эквайринга', 'forPay' => '46.08', 'acquiringFee' => '-46.08']));

        self::assertSame(['wb:field:acquiringFee' => [4608, 'acquiring']], $this->summary($components));
        self::assertSame(4608, $this->total($components));
    }

    public function testPayoutWithoutRevenueWaitsForDecision(): void
    {
        $components = $this->translate($this->row(['docTypeName' => 'Продажа', 'sellerOperName' => 'Добровольная компенсация при возврате', 'forPay' => '300']));

        self::assertSame(['wb:payout:добровольная компенсация при возврате' => [30000, null]], $this->summary($components));
    }

    public function testCostFieldsKeepSourceSign(): void
    {
        $components = $this->translate($this->row([
            'sellerOperName' => 'Доставка', 'deliveryService' => '95.50', 'returnAmount' => 1,
            'paidStorage' => '-1.20', 'penalty' => '100', 'rebillLogisticCost' => '5.99', 'paidAcceptance' => '10',
            'cashbackAmount' => '25', 'cashbackDiscount' => '7', 'cashbackCommissionChange' => '3', 'additionalPayment' => '11',
        ]));

        self::assertSame([
            'wb:field:deliveryService:return' => [-9550, 'logistics_back'],
            'wb:field:paidStorage' => [120, 'storage'],
            'wb:field:penalty' => [-10000, 'fine'],
            'wb:field:rebillLogisticCost' => [-599, null],
            'wb:field:paidAcceptance' => [-1000, null],
            'wb:field:cashbackAmount' => [-2500, 'promo_reviews'],
            'wb:field:cashbackCommissionChange' => [-300, null],
            'wb:field:cashbackDiscount' => [700, 'compensation'],
            'wb:field:additionalPayment' => [1100, 'compensation'],
        ], $this->summary($components));
    }

    public function testDeliveryWithoutReturnIsLogisticsTo(): void
    {
        self::assertSame(['wb:field:deliveryService' => [-9550, 'logistics_to']], $this->summary($this->translate($this->row(['sellerOperName' => 'Доставка', 'deliveryService' => '95.50']))));
    }

    public function testPvzRewardCountsOnlyOnPvzRows(): void
    {
        self::assertSame(['wb:field:ppvzReward' => [-712, null]], $this->summary($this->translate($this->row(['docTypeName' => 'Продажа', 'sellerOperName' => 'Возмещение за выдачу и возврат товаров на ПВЗ', 'ppvzReward' => '7.1234']))));
        self::assertArrayNotHasKey('wb:field:ppvzReward', $this->summary($this->translate(['ppvzReward' => '90'] + $this->sale())), 'в продаже — внутри комиссии');
    }

    public function testDeductionReasonDropsDocumentNumberAndMonth(): void
    {
        $promo = $this->translate($this->row(['sellerOperName' => 'Удержание', 'deduction' => '24000', 'bonusTypeName' => 'Оказание услуг «WB Продвижение», документ №319926569']));
        $disposal = $this->translate($this->row(['sellerOperName' => 'Удержание', 'deduction' => '88', 'bonusTypeName' => 'Отчет об утилизированном товаре (по складу) за октябрь 2026, документ №323731043']));

        self::assertSame(['wb:deduction:Оказание услуг «WB Продвижение»' => [-2400000, null]], $this->summary($promo));
        self::assertSame(['wb:deduction:Отчет об утилизированном товаре (по складу)' => [-8800, null]], $this->summary($disposal));
        self::assertSame(OperationKind::ADS_SETTLEMENT, WbSourceTypeSeed::kind('wb:deduction:Оказание услуг «WB Продвижение»'));
        self::assertSame(CascadeLevel::INFO, OperationKind::ADS_SETTLEMENT->level(), 'реклама в каноне — из wb.ads.daily (W6)');
        self::assertSame(OperationKind::DISPOSAL, WbSourceTypeSeed::kind('wb:deduction:Отчет об утилизированном товаре (по складу)'));
        self::assertNull(WbSourceTypeSeed::kind('wb:field:paidAcceptance'), '❓ — решает администратор');
    }

    public function testEmptyRowGivesNothingAndEmptySizeIsUnknown(): void
    {
        self::assertSame([], $this->translate($this->row(['sellerOperName' => 'Хранение'])));
        self::assertSame('UNKNOWN', $this->translate(['techSize' => ''] + $this->sale())[0]->size);
    }

    /**
     * @return array<string, mixed>
     */
    private function sale(): array
    {
        return $this->row([
            'docTypeName' => 'Продажа', 'sellerOperName' => 'Продажа', 'nmId' => 123, 'techSize' => '42', 'quantity' => 2,
            'retailPriceWithDisc' => '1000', 'retailAmount' => '1300', 'forPay' => '1500', 'acquiringFee' => '30',
            'ppvzReward' => '0', 'vw' => '470',
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function row(array $fields): array
    {
        return $fields + ['docTypeName' => '', 'currency' => 'RUB', 'quantity' => 0, 'nmId' => 0, 'techSize' => '', 'rrdId' => 1];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<WbComponent>
     */
    private function translate(array $row): array
    {
        return (new SalesReportTranslator())->translate('wb:sales-report.daily:1', $row);
    }

    /**
     * @param list<WbComponent> $components
     *
     * @return array<string, array{int, string|null}>
     */
    private function summary(array $components): array
    {
        $summary = [];
        foreach ($components as $c) {
            $summary[$c->sourceType] = [$c->amount->amountMinor(), $c->kind?->value];
        }

        return $summary;
    }

    /**
     * @param list<WbComponent> $components
     */
    private function amount(array $components, string $sourceType): int
    {
        return $this->summary($components)[$sourceType][0];
    }

    /**
     * @param list<WbComponent> $components
     */
    private function total(array $components): int
    {
        return array_sum(array_map(static fn (WbComponent $c): int => SalesReportTranslator::isInformational($c) ? 0 : $c->amount->amountMinor(), $components));
    }
}
