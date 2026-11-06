<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settlement;

use App\Settlement\Domain\SettlementPostingPolicy;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Enum\SettlementBucket;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SettlementPostingPolicyTest extends TestCase
{
    public function testPartialPaymentCannotOverspendAndPreservesExactMinorUnits(): void
    {
        $company = Uuid::uuid7()->toString();
        $party = Uuid::uuid7()->toString();
        $date = new \DateTimeImmutable('2026-11-01');
        $obligation = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::PAYABLE, Money::fromString('100000.00', 'RUB'), $date);
        $payment = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::UNALLOCATED_OUT, Money::fromString('40000.00', 'RUB'), $date);
        $policy = new SettlementPostingPolicy();
        $policy->assertAllocation($obligation, $payment, Money::fromString('40000.00', 'RUB'), $date);
        self::assertSame(4000000, $payment->getAmount()->amountMinor());
        $this->expectException(\DomainException::class);
        $policy->assertAllocation($obligation, $payment, Money::fromString('40000.01', 'RUB'), $date);
    }

    public function testPaymentCannotSettleDifferentCounterparty(): void
    {
        $company = Uuid::uuid7()->toString();
        $date = new \DateTimeImmutable('2026-11-01');
        $debt = new SettlementItem($company, Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), SettlementBucket::RECEIVABLE, Money::fromString('0.30', 'RUB'), $date);
        $payment = new SettlementItem($company, Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), SettlementBucket::ADVANCE_RECEIVED, Money::fromString('0.30', 'RUB'), $date);
        $this->expectException(\DomainException::class);
        (new SettlementPostingPolicy())->assertAllocation($debt, $payment, Money::fromString('0.10', 'RUB'), $date);
    }

    public function testExactKopecksAndEconomicDirectionAreValidated(): void
    {
        $company = Uuid::uuid7()->toString();
        $party = Uuid::uuid7()->toString();
        $date = new \DateTimeImmutable('2026-11-01');
        $amount = Money::fromString('0.10', 'RUB')->add(Money::fromString('0.20', 'RUB'));
        $debt = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::PAYABLE, $amount, $date);
        $payment = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::ADVANCE_GIVEN, $amount, $date);
        (new SettlementPostingPolicy())->assertAllocation($debt, $payment, $amount, $date);
        self::assertSame('0.30', $amount->toDecimalString());
        $this->expectException(\DomainException::class);
        (new SettlementPostingPolicy())->assertAllocation($debt, $payment, Money::fromString('0.31', 'RUB'), $date);
    }
}
