<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transfer;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transfer\CashTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
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

    public function findOneDetailedByIdAndCompanyId(string $id, string $companyId): ?CashTransfer
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        return $this->createQueryBuilder('transfer')
            ->addSelect(
                'sourceTransaction',
                'sourceAccount',
                'sourceSplit',
                'sourceCategory',
                'targetTransaction',
                'targetAccount',
                'targetSplit',
                'targetCategory',
            )
            ->innerJoin('transfer.sourceTransaction', 'sourceTransaction')
            ->innerJoin('sourceTransaction.moneyAccount', 'sourceAccount')
            ->innerJoin('sourceTransaction.splits', 'sourceSplit')
            ->innerJoin('sourceSplit.cashflowCategory', 'sourceCategory')
            ->innerJoin('transfer.targetTransaction', 'targetTransaction')
            ->innerJoin('targetTransaction.moneyAccount', 'targetAccount')
            ->innerJoin('targetTransaction.splits', 'targetSplit')
            ->innerJoin('targetSplit.cashflowCategory', 'targetCategory')
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

    /**
     * @param list<string> $transactionIds
     *
     * @return list<CashTransfer>
     */
    public function findByTransactionIdsAndCompanyId(array $transactionIds, string $companyId): array
    {
        Assert::uuid($companyId);
        if ([] === $transactionIds) {
            return [];
        }

        return $this->createQueryBuilder('transfer')
            ->addSelect('sourceTransaction', 'targetTransaction')
            ->innerJoin('transfer.sourceTransaction', 'sourceTransaction')
            ->innerJoin('transfer.targetTransaction', 'targetTransaction')
            ->andWhere('IDENTITY(transfer.company) = :companyId')
            ->andWhere('IDENTITY(sourceTransaction.company) = :companyId')
            ->andWhere('IDENTITY(targetTransaction.company) = :companyId')
            ->andWhere('(sourceTransaction.id IN (:transactionIds) OR targetTransaction.id IN (:transactionIds))')
            ->setParameter('companyId', $companyId)
            ->setParameter('transactionIds', $transactionIds)
            ->getQuery()
            ->getResult();
    }

    /** @return Pagerfanta<CashTransfer> */
    public function paginateDeletedByCompanyId(string $companyId, int $page, int $perPage): Pagerfanta
    {
        Assert::uuid($companyId);

        $queryBuilder = $this->createQueryBuilder('transfer')
            ->addSelect('sourceTransaction', 'sourceAccount', 'targetTransaction', 'targetAccount')
            ->innerJoin('transfer.sourceTransaction', 'sourceTransaction')
            ->innerJoin('sourceTransaction.moneyAccount', 'sourceAccount')
            ->innerJoin('transfer.targetTransaction', 'targetTransaction')
            ->innerJoin('targetTransaction.moneyAccount', 'targetAccount')
            ->andWhere('IDENTITY(transfer.company) = :companyId')
            ->andWhere('transfer.deletedAt IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->orderBy('transfer.deletedAt', 'DESC');

        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage($perPage);
        $pager->setAllowOutOfRangePages(true);
        $pager->setCurrentPage($page);

        return $pager;
    }
}
