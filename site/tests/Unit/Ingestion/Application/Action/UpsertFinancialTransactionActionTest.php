<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Action;

use App\Ingestion\Application\Action\UpsertFinancialTransactionAction;
use App\Ingestion\Application\Command\UpsertFinancialTransactionCommand;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class UpsertFinancialTransactionActionTest extends TestCase
{
    public function testSameSourceDataStillUpdatesMissingListingEnrichment(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rawRecordId = Uuid::uuid7()->toString();
        $operationGroupId = Uuid::uuid7()->toString();
        $sourceData = ['sku' => '286079488', 'amount' => '100.00'];
        $externalUpdatedAt = new \DateTimeImmutable('2026-06-01 00:00:00');
        $occurredAt = new \DateTimeImmutable('2026-06-01 12:00:00');

        $transaction = new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            externalId: 'ozon:accrual-by-day:1:sale:product-0',
            externalUpdatedAt: $externalUpdatedAt,
            operationGroupId: $operationGroupId,
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            money: Money::fromMinor(10000, 'RUB'),
            occurredAt: $occurredAt,
            rawRecordId: $rawRecordId,
            sourceData: $sourceData,
        );

        $repository = $this->createMock(FinancialTransactionRepository::class);
        $repository
            ->method('findByNaturalKey')
            ->with($companyId, IngestSource::OZON, 'ozon:accrual-by-day:1:sale:product-0', TransactionType::SALE)
            ->willReturn($transaction);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $action = new UpsertFinancialTransactionAction($repository, $entityManager);

        $result = $action(new UpsertFinancialTransactionCommand(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            mapped: new MappedTransaction(
                externalId: 'ozon:accrual-by-day:1:sale:product-0',
                externalUpdatedAt: $externalUpdatedAt,
                operationGroupId: $operationGroupId,
                type: TransactionType::SALE,
                direction: TransactionDirection::IN,
                money: Money::fromMinor(10000, 'RUB'),
                occurredAt: $occurredAt,
                sourceData: $sourceData,
            ),
            rawRecordId: $rawRecordId,
            counterpartyId: null,
            listingId: '44444444-4444-4444-8444-444444444444',
            listingSku: '286079488',
        ));

        self::assertNotNull($result);
        self::assertSame($transaction->getId(), $result->transactionId);
        self::assertFalse($result->periodChanged);
        self::assertSame($occurredAt, $result->oldOccurredAt);
        self::assertSame($occurredAt, $result->newOccurredAt);
        self::assertSame(10000, $transaction->getAmountMinor());
        self::assertSame($externalUpdatedAt, $transaction->getExternalUpdatedAt());
        self::assertSame($sourceData, $transaction->getSourceData());
        self::assertSame('44444444-4444-4444-8444-444444444444', $transaction->getListingId());
        self::assertSame('286079488', $transaction->getListingSku());
    }

    public function testSameSourceDataDoesNotOverwriteExistingListingEnrichment(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rawRecordId = Uuid::uuid7()->toString();
        $operationGroupId = Uuid::uuid7()->toString();
        $sourceData = ['sku' => '286079488', 'amount' => '100.00'];
        $externalUpdatedAt = new \DateTimeImmutable('2026-06-01 00:00:00');
        $occurredAt = new \DateTimeImmutable('2026-06-01 12:00:00');

        $transaction = new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            externalId: 'ozon:accrual-by-day:1:sale:product-0',
            externalUpdatedAt: $externalUpdatedAt,
            operationGroupId: $operationGroupId,
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            money: Money::fromMinor(10000, 'RUB'),
            occurredAt: $occurredAt,
            rawRecordId: $rawRecordId,
            sourceData: $sourceData,
            listingId: '33333333-3333-4333-8333-333333333333',
            listingSku: 'old-sku',
        );

        $repository = $this->createMock(FinancialTransactionRepository::class);
        $repository
            ->method('findByNaturalKey')
            ->with($companyId, IngestSource::OZON, 'ozon:accrual-by-day:1:sale:product-0', TransactionType::SALE)
            ->willReturn($transaction);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $action = new UpsertFinancialTransactionAction($repository, $entityManager);

        $result = $action(new UpsertFinancialTransactionCommand(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            mapped: new MappedTransaction(
                externalId: 'ozon:accrual-by-day:1:sale:product-0',
                externalUpdatedAt: $externalUpdatedAt,
                operationGroupId: $operationGroupId,
                type: TransactionType::SALE,
                direction: TransactionDirection::IN,
                money: Money::fromMinor(10000, 'RUB'),
                occurredAt: $occurredAt,
                sourceData: $sourceData,
            ),
            rawRecordId: $rawRecordId,
            counterpartyId: null,
            listingId: '44444444-4444-4444-8444-444444444444',
            listingSku: '286079488',
        ));

        self::assertNull($result);
        self::assertSame('33333333-3333-4333-8333-333333333333', $transaction->getListingId());
        self::assertSame('old-sku', $transaction->getListingSku());
    }

    public function testSameSourceDataStillRefreshesRawRecordAttribution(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $oldRawRecordId = Uuid::uuid7()->toString();
        $newRawRecordId = Uuid::uuid7()->toString();
        $operationGroupId = Uuid::uuid7()->toString();
        $sourceData = ['sku' => '286079488', 'amount' => '100.00'];
        $externalUpdatedAt = new \DateTimeImmutable('2026-06-01 00:00:00');
        $newExternalUpdatedAt = new \DateTimeImmutable('2026-06-02 00:00:00');
        $occurredAt = new \DateTimeImmutable('2026-06-01 12:00:00');

        $transaction = new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            externalId: 'ozon:accrual-by-day:1:sale:product-0',
            externalUpdatedAt: $externalUpdatedAt,
            operationGroupId: $operationGroupId,
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            money: Money::fromMinor(10000, 'RUB'),
            occurredAt: $occurredAt,
            rawRecordId: $oldRawRecordId,
            sourceData: $sourceData,
        );

        $repository = $this->createMock(FinancialTransactionRepository::class);
        $repository
            ->method('findByNaturalKey')
            ->with($companyId, IngestSource::OZON, 'ozon:accrual-by-day:1:sale:product-0', TransactionType::SALE)
            ->willReturn($transaction);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $action = new UpsertFinancialTransactionAction($repository, $entityManager);

        $result = $action(new UpsertFinancialTransactionCommand(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            mapped: new MappedTransaction(
                externalId: 'ozon:accrual-by-day:1:sale:product-0',
                externalUpdatedAt: $newExternalUpdatedAt,
                operationGroupId: $operationGroupId,
                type: TransactionType::SALE,
                direction: TransactionDirection::IN,
                money: Money::fromMinor(10000, 'RUB'),
                occurredAt: $occurredAt,
                sourceData: $sourceData,
            ),
            rawRecordId: $newRawRecordId,
            counterpartyId: null,
            listingId: null,
            listingSku: null,
        ));

        self::assertNotNull($result);
        self::assertSame($newRawRecordId, $transaction->getRawRecordId());
        self::assertSame($newExternalUpdatedAt, $transaction->getExternalUpdatedAt());
        self::assertFalse($result->periodChanged);
    }

    public function testSameSourceDataDoesNotMoveRawRecordAttributionBackwards(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $currentRawRecordId = Uuid::uuid7()->toString();
        $staleRawRecordId = Uuid::uuid7()->toString();
        $operationGroupId = Uuid::uuid7()->toString();
        $sourceData = ['sku' => '286079488', 'amount' => '100.00'];
        $currentExternalUpdatedAt = new \DateTimeImmutable('2026-06-02 00:00:00');
        $staleExternalUpdatedAt = new \DateTimeImmutable('2026-06-01 00:00:00');
        $occurredAt = new \DateTimeImmutable('2026-06-01 12:00:00');

        $transaction = new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            externalId: 'ozon:accrual-by-day:1:sale:product-0',
            externalUpdatedAt: $currentExternalUpdatedAt,
            operationGroupId: $operationGroupId,
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            money: Money::fromMinor(10000, 'RUB'),
            occurredAt: $occurredAt,
            rawRecordId: $currentRawRecordId,
            sourceData: $sourceData,
        );

        $repository = $this->createMock(FinancialTransactionRepository::class);
        $repository
            ->method('findByNaturalKey')
            ->with($companyId, IngestSource::OZON, 'ozon:accrual-by-day:1:sale:product-0', TransactionType::SALE)
            ->willReturn($transaction);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $action = new UpsertFinancialTransactionAction($repository, $entityManager);

        $result = $action(new UpsertFinancialTransactionCommand(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            mapped: new MappedTransaction(
                externalId: 'ozon:accrual-by-day:1:sale:product-0',
                externalUpdatedAt: $staleExternalUpdatedAt,
                operationGroupId: $operationGroupId,
                type: TransactionType::SALE,
                direction: TransactionDirection::IN,
                money: Money::fromMinor(10000, 'RUB'),
                occurredAt: $occurredAt,
                sourceData: $sourceData,
            ),
            rawRecordId: $staleRawRecordId,
            counterpartyId: null,
            listingId: null,
            listingSku: null,
        ));

        self::assertNull($result);
        self::assertSame($currentRawRecordId, $transaction->getRawRecordId());
        self::assertSame($currentExternalUpdatedAt, $transaction->getExternalUpdatedAt());
    }
}
