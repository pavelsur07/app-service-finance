<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deals;

use App\Deals\Entity\DealAdjustment;
use App\Deals\Entity\DealCharge;
use App\Deals\Entity\DealItem;
use App\Deals\Enum\DealAdjustmentType;
use App\Deals\Enum\DealItemKind;
use App\Deals\Service\DealTotalsCalculator;
use App\Tests\Builders\Deals\ChargeTypeBuilder;
use App\Tests\Builders\Deals\DealBuilder;
use PHPUnit\Framework\TestCase;

final class DealTotalsCalculatorTest extends TestCase
{
    public function testRecalcAggregatesItemsChargesAndAdjustments(): void
    {
        $deal = DealBuilder::aDeal()->build();

        $deal->addItem(new DealItem(
            name: 'Item A',
            kind: DealItemKind::GOOD,
            qty: '2.00',
            price: '50.00',
            amount: '100.00',
            lineIndex: 1,
            deal: $deal,
        ));

        $deal->addItem(new DealItem(
            name: 'Item B',
            kind: DealItemKind::SERVICE,
            qty: '1.00',
            price: '50.00',
            amount: '50.00',
            lineIndex: 2,
            deal: $deal,
        ));

        $chargeType = ChargeTypeBuilder::aChargeType()->build();
        $deal->addCharge(new DealCharge(
            recognizedAt: new \DateTimeImmutable('2024-02-01'),
            amount: '12.00',
            chargeType: $chargeType,
            deal: $deal,
        ));

        $deal->addCharge(new DealCharge(
            recognizedAt: new \DateTimeImmutable('2024-02-02'),
            amount: '8.00',
            chargeType: $chargeType,
            deal: $deal,
        ));

        $deal->addAdjustment(new DealAdjustment(
            recognizedAt: new \DateTimeImmutable('2024-02-03'),
            amount: '20.00',
            type: DealAdjustmentType::RETURN,
            deal: $deal,
        ));

        $deal->addAdjustment(new DealAdjustment(
            recognizedAt: new \DateTimeImmutable('2024-02-04'),
            amount: '5.00',
            type: DealAdjustmentType::DISCOUNT,
            deal: $deal,
        ));

        $deal->addAdjustment(new DealAdjustment(
            recognizedAt: new \DateTimeImmutable('2024-02-05'),
            amount: '10.00',
            type: DealAdjustmentType::CORRECTION,
            deal: $deal,
        ));

        $calculator = new DealTotalsCalculator();
        $calculator->recalc($deal);

        self::assertSame('135.00', $deal->getTotalAmount());
        self::assertSame('20.00', $deal->getTotalDirectCost());
    }
}
