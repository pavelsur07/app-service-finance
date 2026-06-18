<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Entity;

use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Exception\StaleTransactionUpdateException;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class FinancialTransactionTest extends TestCase
{
    public function testConstructorStoresCanonicalFields(): void
    {
        $transaction = $this->newTransaction();

        self::assertTrue(Uuid::isValid($transaction->getId()));
        self::assertSame(TransactionType::SALE, $transaction->getType());
        self::assertSame(TransactionDirection::IN, $transaction->getDirection());
        self::assertSame(12500, $transaction->getAmountMinor());
        self::assertSame('RUB', $transaction->getCurrency());
        self::assertSame('Europe/Moscow', $transaction->getSourceTz());
        self::assertSame(['row' => 1], $transaction->getSourceData());
    }

    public function testReplaceFromNewerVersionUpdatesMutableFieldsAndKeepsOldOccurredAt(): void
    {
        $transaction = $this->newTransaction();
        $oldOccurredAt = $transaction->getOccurredAt();
        $newOccurredAt = new \DateTimeImmutable('2026-06-03 12:00:00');

        $transaction->replaceFromNewerVersion(
            money: Money::fromMinor(15000, 'RUB'),
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            occurredAt: $newOccurredAt,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-04 00:00:00'),
            orderRef: 'order-2',
            payoutRef: 'payout-2',
            counterpartyId: null,
            description: 'updated',
            sourceData: ['row' => 2],
        );

        self::assertSame(15000, $transaction->getAmountMinor());
        self::assertSame($newOccurredAt, $transaction->getOccurredAt());
        self::assertSame($oldOccurredAt, $transaction->oldOccurredAt());
        self::assertSame('order-2', $transaction->getOrderRef());
        self::assertSame(['row' => 2], $transaction->getSourceData());
    }

    public function testReplaceRejectsStaleVersion(): void
    {
        $transaction = $this->newTransaction();

        $this->expectException(StaleTransactionUpdateException::class);

        $transaction->replaceFromNewerVersion(
            money: Money::fromMinor(15000, 'RUB'),
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            occurredAt: new \DateTimeImmutable('2026-06-03 12:00:00'),
            externalUpdatedAt: new \DateTimeImmutable('2026-06-01 00:00:00'),
            orderRef: null,
            payoutRef: null,
            counterpartyId: null,
            description: null,
            sourceData: [],
        );
    }

    private function newTransaction(): FinancialTransaction
    {
        return new FinancialTransaction(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            externalId: 'external-1',
            externalUpdatedAt: new \DateTimeImmutable('2026-06-02 00:00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            money: Money::fromMinor(12500, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-02 12:00:00'),
            rawRecordId: Uuid::uuid7()->toString(),
            orderRef: 'order-1',
            payoutRef: 'payout-1',
            counterpartyId: null,
            description: 'sale',
            sourceData: ['row' => 1],
            sourceTz: 'Europe/Moscow',
        );
    }
}
