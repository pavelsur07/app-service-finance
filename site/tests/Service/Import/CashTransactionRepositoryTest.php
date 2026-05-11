<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use Ramsey\Uuid\Uuid;

class CashTransactionRepositoryTest extends ClientBank1CImportServiceTestCase
{
    public function testFindActiveByCompanyAccountExternalIdDoesNotReturnSoftDeletedTransaction(): void
    {
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $this->company,
            $this->account,
            CashDirection::OUTFLOW,
            '100.00',
            'RUB',
            new \DateTimeImmutable('2024-01-10')
        );
        $transaction->setExternalId('ext-soft-deleted');
        $transaction->markDeleted('test-user', 'soft delete for test');

        $this->em->persist($transaction);
        $this->em->flush();

        $found = $this->transactionRepository->findActiveByCompanyAccountExternalId(
            $this->company,
            $this->account,
            'ext-soft-deleted'
        );

        self::assertNull($found);
    }

    public function testFindIdByCompanyImportSourceExternalIdDbalReturnsTransactionId(): void
    {
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $this->company,
            $this->account,
            CashDirection::OUTFLOW,
            '150.00',
            'RUB',
            new \DateTimeImmutable('2024-01-11')
        );
        $transaction->setImportSource('telegram');
        $transaction->setExternalId('ext-dbal-lookup');

        $this->em->persist($transaction);
        $this->em->flush();

        $foundId = $this->transactionRepository->findIdByCompanyImportSourceExternalIdDbal(
            $this->company->getId(),
            'telegram',
            'ext-dbal-lookup',
        );

        self::assertSame($transaction->getId(), $foundId);
    }

}
