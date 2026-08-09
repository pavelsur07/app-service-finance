<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transfer;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transfer\CashTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<CashTransfer>
 */
final class CashTransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashTransfer::class);
    }

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?CashTransfer
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        return $this->createQueryBuilder('transfer')
            ->andWhere('transfer.id = :id')
            ->andWhere('IDENTITY(transfer.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByIdAndCompanyIdForUpdate(string $id, string $companyId): ?CashTransfer
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        return $this->createQueryBuilder('transfer')
            ->andWhere('transfer.id = :id')
            ->andWhere('IDENTITY(transfer.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    public function findOneByCompanyIdAndIdempotencyKey(string $companyId, string $idempotencyKey): ?CashTransfer
    {
        Assert::uuid($companyId);

        return $this->createQueryBuilder('transfer')
            ->andWhere('IDENTITY(transfer.company) = :companyId')
            ->andWhere('transfer.idempotencyKey = :idempotencyKey')
            ->setParameter('companyId', $companyId)
            ->setParameter('idempotencyKey', $idempotencyKey)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByTransactionAndCompanyId(CashTransaction $transaction, string $companyId): ?CashTransfer
    {
        Assert::uuid($companyId);

        return $this->createQueryBuilder('transfer')
            ->andWhere('IDENTITY(transfer.company) = :companyId')
            ->andWhere('(transfer.sourceTransaction = :transaction OR transfer.targetTransaction = :transaction)')
            ->setParameter('companyId', $companyId)
            ->setParameter('transaction', $transaction)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
