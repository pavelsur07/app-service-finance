<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settlement;

use App\Settlement\Domain\SettlementPostingPolicy;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Enum\SettlementBucket;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SettlementAvailableCapacityTest extends TestCase
{
    public function testPositiveCorrectionCanRaiseDebtAboveOriginalAmount(): void
    {
        $company = Uuid::uuid7()->toString();
        $party = Uuid::uuid7()->toString();
        $date = new \DateTimeImmutable('2026-11-01');
        $debt = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::PAYABLE, Money::fromString('100.00', 'RUB'), $date);
        $payment = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::UNALLOCATED_OUT, Money::fromString('150.00', 'RUB'), $date);
        (new SettlementPostingPolicy())->assertAllocation($debt, $payment, Money::fromString('150.00', 'RUB'), $date, Money::fromString('200.00', 'RUB'), Money::fromString('150.00', 'RUB'));
        $this->addToAssertionCount(1);
    }

    public function testAvailableBalanceCanBeLessThanOriginalAmount(): void
    {
        $company = Uuid::uuid7()->toString();
        $party = Uuid::uuid7()->toString();
        $date = new \DateTimeImmutable('2026-11-01');
        $debt = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::PAYABLE, Money::fromString('100.00', 'RUB'), $date);
        $payment = new SettlementItem($company, Uuid::uuid7()->toString(), $party, SettlementBucket::UNALLOCATED_OUT, Money::fromString('100.00', 'RUB'), $date);
        $this->expectException(\DomainException::class);
        (new SettlementPostingPolicy())->assertAllocation($debt, $payment, Money::fromString('60.00', 'RUB'), $date, Money::fromString('50.00', 'RUB'), Money::fromString('100.00', 'RUB'));
    }
}
