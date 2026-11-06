<?php

declare(strict_types=1);

namespace App\Settlement\Domain;

use App\Cash\Enum\Transaction\CashDirection;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementSourceType;
use App\Shared\Domain\ValueObject\Money;

final readonly class SettlementPostingPolicy
{
    public function paymentBucket(CashDirection $direction, bool $isAdvance): SettlementBucket
    {
        return match ($direction) {
            CashDirection::OUTFLOW => $isAdvance ? SettlementBucket::ADVANCE_GIVEN : SettlementBucket::UNALLOCATED_OUT,
            CashDirection::INFLOW => $isAdvance ? SettlementBucket::ADVANCE_RECEIVED : SettlementBucket::UNALLOCATED_IN,
        };
    }

    public function assertBaseDocument(SettlementDocumentKind $kind, SettlementBucket $bucket, SettlementSourceType $sourceType, ?string $sourceId, \DateTimeImmutable $date, \DateTimeImmutable $startDate): void
    {
        $buckets = match ($kind) {
            SettlementDocumentKind::OPENING => [SettlementBucket::RECEIVABLE, SettlementBucket::PAYABLE, SettlementBucket::ADVANCE_GIVEN, SettlementBucket::ADVANCE_RECEIVED],
            SettlementDocumentKind::OBLIGATION => [SettlementBucket::RECEIVABLE, SettlementBucket::PAYABLE],
            SettlementDocumentKind::PAYMENT => [SettlementBucket::ADVANCE_GIVEN, SettlementBucket::ADVANCE_RECEIVED, SettlementBucket::UNALLOCATED_OUT, SettlementBucket::UNALLOCATED_IN],
            default => [],
        };
        $sourceValid = match ($kind) {
            SettlementDocumentKind::OPENING => SettlementSourceType::MANUAL === $sourceType && null === $sourceId,
            SettlementDocumentKind::OBLIGATION => (SettlementSourceType::MANUAL === $sourceType && null === $sourceId) || (SettlementSourceType::FINANCE === $sourceType && null !== $sourceId),
            SettlementDocumentKind::PAYMENT => SettlementSourceType::CASH === $sourceType && null !== $sourceId,
            default => false,
        };
        if (!$sourceValid || !in_array($bucket, $buckets, true)) {
            throw new \DomainException('Вид документа, корзина и источник не согласованы.');
        }
        if (SettlementDocumentKind::OPENING === $kind && $date->format('Y-m-d') !== $startDate->format('Y-m-d')) {
            throw new \DomainException('Начальный остаток должен относиться к дате начала учёта.');
        }
    }

    /** Available balances are read under the company book lock; corrections may change original capacity. */
    public function assertAllocation(SettlementItem $obligation, SettlementItem $payment, Money $amount, \DateTimeImmutable $date, Money $obligationAvailable, Money $paymentAvailable): void
    {
        $this->assertAllocationPair($obligation, $payment, $amount, $date);
        if ($amount->currency() !== $obligationAvailable->currency()
            || $amount->currency() !== $paymentAvailable->currency()
            || $amount->compareTo($obligationAvailable) > 0
            || $amount->compareTo($paymentAvailable) > 0) {
            throw new \DomainException('Недостаточно свободного остатка.');
        }
    }

    /** Identity/direction invariants also apply when independently replaying all primary facts. */
    public function assertAllocationPair(SettlementItem $obligation, SettlementItem $payment, Money $amount, \DateTimeImmutable $date): void
    {
        $allowed = match ($obligation->getBucket()) {
            SettlementBucket::RECEIVABLE => [SettlementBucket::ADVANCE_RECEIVED, SettlementBucket::UNALLOCATED_IN],
            SettlementBucket::PAYABLE => [SettlementBucket::ADVANCE_GIVEN, SettlementBucket::UNALLOCATED_OUT],
            default => [],
        };
        if ($obligation->getCompanyId() !== $payment->getCompanyId()
            || $obligation->getCounterpartyId() !== $payment->getCounterpartyId()
            || !in_array($payment->getBucket(), $allowed, true)
            || $amount->currency() !== $obligation->getAmount()->currency()
            || $amount->currency() !== $payment->getAmount()->currency()
            || !$amount->isPositive()
            || $date->format('Y-m-d') < $obligation->getEffectiveDate()->format('Y-m-d')
            || $date->format('Y-m-d') < $payment->getEffectiveDate()->format('Y-m-d')) {
            throw new \DomainException('Недопустимое распределение оплаты.');
        }
    }
}
