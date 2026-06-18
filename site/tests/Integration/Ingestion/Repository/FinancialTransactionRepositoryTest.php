<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class FinancialTransactionRepositoryTest extends IntegrationTestCase
{
    public function testNaturalKeyLookupUsesCompanyId(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();
        $transactionA = $this->newTransaction($companyA, 'external-1', TransactionType::SALE);
        $transactionB = $this->newTransaction($companyB, 'external-1', TransactionType::SALE);

        $this->em->persist($transactionA);
        $this->em->persist($transactionB);
        $this->em->flush();
        $this->em->clear();

        /** @var FinancialTransactionRepository $repository */
        $repository = self::getContainer()->get(FinancialTransactionRepository::class);

        self::assertSame(
            $transactionA->getId(),
            $repository->findByNaturalKey($companyA, IngestSource::OZON, 'external-1', TransactionType::SALE)?->getId(),
        );
        self::assertSame(
            $transactionB->getId(),
            $repository->findByNaturalKey($companyB, IngestSource::OZON, 'external-1', TransactionType::SALE)?->getId(),
        );
    }

    public function testPeriodAndRawLookupsUseCompanyAndShop(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $operationGroupId = Uuid::uuid7()->toString();
        $rawRecordId = Uuid::uuid7()->toString();
        $shopTransaction = $this->newTransaction($companyId, 'external-1', TransactionType::SALE, 'shop-1', $operationGroupId, $rawRecordId);
        $otherShopTransaction = $this->newTransaction($companyId, 'external-2', TransactionType::COMMISSION, 'shop-2', $operationGroupId, Uuid::uuid7()->toString());

        $this->em->persist($shopTransaction);
        $this->em->persist($otherShopTransaction);
        $this->em->flush();
        $this->em->clear();

        /** @var FinancialTransactionRepository $repository */
        $repository = self::getContainer()->get(FinancialTransactionRepository::class);

        self::assertSame(
            [$shopTransaction->getId(), $otherShopTransaction->getId()],
            array_map(
                static fn (FinancialTransaction $transaction): string => $transaction->getId(),
                $repository->findByOperationGroup($companyId, $operationGroupId),
            ),
        );
        self::assertSame(
            [$shopTransaction->getId()],
            array_map(
                static fn (FinancialTransaction $transaction): string => $transaction->getId(),
                iterator_to_array($repository->iterateByPeriod(
                    $companyId,
                    new \DateTimeImmutable('2026-06-01 00:00:00'),
                    new \DateTimeImmutable('2026-06-30 23:59:59'),
                    'shop-1',
                )),
            ),
        );
        self::assertSame(
            [$shopTransaction->getId()],
            array_map(
                static fn (FinancialTransaction $transaction): string => $transaction->getId(),
                $repository->findByRawRecordId($companyId, $rawRecordId),
            ),
        );
    }

    private function newTransaction(
        string $companyId,
        string $externalId,
        TransactionType $type,
        string $shopRef = 'shop-1',
        ?string $operationGroupId = null,
        ?string $rawRecordId = null,
    ): FinancialTransaction {
        return new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: $shopRef,
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-02 00:00:00'),
            operationGroupId: $operationGroupId ?? Uuid::uuid7()->toString(),
            type: $type,
            direction: TransactionType::COMMISSION === $type ? TransactionDirection::OUT : TransactionDirection::IN,
            money: Money::fromMinor(10000, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-02 12:00:00'),
            rawRecordId: $rawRecordId ?? Uuid::uuid7()->toString(),
        );
    }
}
