<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\UpsertFinancialTransactionAction;
use App\Ingestion\Application\Command\UpsertFinancialTransactionCommand;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class UpsertFinancialTransactionActionTest extends IntegrationTestCase
{
    public function testCreatesSkipsStaleAndUpdatesOnlyNewerTransactionVersion(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rawRecordId = Uuid::uuid7()->toString();
        $operationGroupId = Uuid::uuid7()->toString();

        /** @var UpsertFinancialTransactionAction $action */
        $action = self::getContainer()->get(UpsertFinancialTransactionAction::class);

        $created = $action($this->command(
            companyId: $companyId,
            rawRecordId: $rawRecordId,
            mapped: $this->mapped(
                operationGroupId: $operationGroupId,
                externalUpdatedAt: new \DateTimeImmutable('2026-06-18 10:00:00'),
                occurredAt: new \DateTimeImmutable('2026-06-18 09:00:00'),
                amountMinor: 10000,
            ),
        ));
        $this->em->flush();
        $this->em->clear();

        self::assertNotNull($created);
        self::assertNull($created->oldOccurredAt);

        $stale = $action($this->command(
            companyId: $companyId,
            rawRecordId: $rawRecordId,
            mapped: $this->mapped(
                operationGroupId: $operationGroupId,
                externalUpdatedAt: new \DateTimeImmutable('2026-06-18 10:00:00'),
                occurredAt: new \DateTimeImmutable('2026-06-19 09:00:00'),
                amountMinor: 20000,
            ),
        ));

        self::assertNull($stale);

        $updated = $action($this->command(
            companyId: $companyId,
            rawRecordId: $rawRecordId,
            mapped: $this->mapped(
                operationGroupId: $operationGroupId,
                externalUpdatedAt: new \DateTimeImmutable('2026-06-18 11:00:00'),
                occurredAt: new \DateTimeImmutable('2026-06-19 09:00:00'),
                amountMinor: 20000,
            ),
        ));
        $this->em->flush();
        $this->em->clear();

        self::assertNotNull($updated);
        self::assertEquals(new \DateTimeImmutable('2026-06-18 09:00:00'), $updated->oldOccurredAt);
        self::assertEquals(new \DateTimeImmutable('2026-06-19 09:00:00'), $updated->newOccurredAt);
        self::assertTrue($updated->periodChanged);

        /** @var FinancialTransactionRepository $repository */
        $repository = self::getContainer()->get(FinancialTransactionRepository::class);
        $transaction = $repository->findByNaturalKey($companyId, IngestSource::OZON, 'external-tx-1', TransactionType::SALE);

        self::assertNotNull($transaction);
        self::assertSame(20000, $transaction->getAmountMinor());
        self::assertEquals(new \DateTimeImmutable('2026-06-19 09:00:00'), $transaction->getOccurredAt());
    }

    private function command(
        string $companyId,
        string $rawRecordId,
        MappedTransaction $mapped,
    ): UpsertFinancialTransactionCommand {
        return new UpsertFinancialTransactionCommand(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            mapped: $mapped,
            rawRecordId: $rawRecordId,
            counterpartyId: null,
        );
    }

    private function mapped(
        string $operationGroupId,
        \DateTimeImmutable $externalUpdatedAt,
        \DateTimeImmutable $occurredAt,
        int $amountMinor,
    ): MappedTransaction {
        return new MappedTransaction(
            externalId: 'external-tx-1',
            externalUpdatedAt: $externalUpdatedAt,
            operationGroupId: $operationGroupId,
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            money: Money::fromMinor($amountMinor, 'RUB'),
            occurredAt: $occurredAt,
        );
    }
}
