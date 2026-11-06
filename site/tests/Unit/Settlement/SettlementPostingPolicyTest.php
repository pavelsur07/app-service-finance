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
        $policy->assertAllocation($obligation, $payment, Money::fromString('40000.00', 'RUB'), $date, $obligation->getAmount(), $payment->getAmount());
        self::assertSame(4000000, $payment->getAmount()->amountMinor());
        $this->expectException(\DomainException::class);
        $policy->assertAllocation($obligation, $payment, Money::fromString('40000.01', 'RUB'), $date, $obligation->getAmount(), $payment->getAmount());
    }

    public function testPaymentCannotSettleDifferentCounterparty(): void
    {
        $company = Uuid::uuid7()->toString();
        $date = new \DateTimeImmutable('2026-11-01');
        $debt = new SettlementItem($company, Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), SettlementBucket::RECEIVABLE, Money::fromString('0.30', 'RUB'), $date);
        $payment = new SettlementItem($company, Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), SettlementBucket::ADVANCE_RECEIVED, Money::fromString('0.30', 'RUB'), $date);
        $this->expectException(\DomainException::class);
        (new SettlementPostingPolicy())->assertAllocation($debt, $payment, Money::fromString('0.10', 'RUB'), $date, $debt->getAmount(), $payment->getAmount());
    }

    public function testExactKopecksAndEconomicDirectionAreValidated(): void
    {
        $company = Uuid::uuid7()->toString();
        $party = Uuid::uuid7()->toString();
        $date = new \DateTimeImmutable('2026-11-01');
        $amount = Money::fromString('0.10', 'RUB')->add(Money::fromString('0.20', 'RUB'));
        $debt = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::PAYABLE, $amount, $date);
        $payment = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::ADVANCE_GIVEN, $amount, $date);
        (new SettlementPostingPolicy())->assertAllocation($debt, $payment, $amount, $date, $debt->getAmount(), $payment->getAmount());
        self::assertSame('0.30', $amount->toDecimalString());
        $this->expectException(\DomainException::class);
        (new SettlementPostingPolicy())->assertAllocation($debt, $payment, Money::fromString('0.31', 'RUB'), $date, $debt->getAmount(), $payment->getAmount());
    }

    /** @return iterable<string, array{string, string, string, bool, SettlementBucket}> */
    public static function invalidAllocations(): iterable
    {
        yield 'zero' => ['0.00', 'RUB', '2026-11-01', false, SettlementBucket::ADVANCE_GIVEN];
        yield 'negative' => ['-0.01', 'RUB', '2026-11-01', false, SettlementBucket::ADVANCE_GIVEN];
        yield 'currency' => ['0.01', 'USD', '2026-11-01', false, SettlementBucket::ADVANCE_GIVEN];
        yield 'earlier date' => ['0.01', 'RUB', '2026-10-31', false, SettlementBucket::ADVANCE_GIVEN];
        yield 'company' => ['0.01', 'RUB', '2026-11-01', true, SettlementBucket::ADVANCE_GIVEN];
        yield 'opposite side' => ['0.01', 'RUB', '2026-11-01', false, SettlementBucket::ADVANCE_RECEIVED];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAllocations')]
    public function testInvalidAllocationHasNoFinancialEffect(string $amount, string $currency, string $date, bool $otherCompany, SettlementBucket $bucket): void
    {
        $company = Uuid::uuid7()->toString();
        $party = Uuid::uuid7()->toString();
        $created = new \DateTimeImmutable('2026-11-01');
        $debt = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::PAYABLE, Money::fromString('100.00', 'RUB'), $created);
        $payment = new SettlementItem($otherCompany ? Uuid::uuid7()->toString() : $company, Uuid::uuid7()->toString(), $party, $bucket, Money::fromString('100.00', 'RUB'), $created);
        $this->expectException(\DomainException::class);
        (new SettlementPostingPolicy())->assertAllocation($debt, $payment, Money::fromString($amount, $currency), new \DateTimeImmutable($date), $debt->getAmount(), $payment->getAmount());
    }
}
