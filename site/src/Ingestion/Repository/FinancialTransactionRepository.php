<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FinancialTransaction>
 */
final class FinancialTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FinancialTransaction::class);
    }

    public function findByNaturalKey(
        string $companyId,
        IngestSource $source,
        string $externalId,
        TransactionType $type,
    ): ?FinancialTransaction {
        return $this->createQueryBuilder('transaction')
            ->andWhere('transaction.companyId = :companyId')
            ->andWhere('transaction.source = :source')
            ->andWhere('transaction.externalId = :externalId')
            ->andWhere('transaction.type = :type')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('externalId', $externalId)
            ->setParameter('type', $type->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<FinancialTransaction>
     */
    public function findByOperationGroup(string $companyId, string $operationGroupId): array
    {
        return $this->createQueryBuilder('transaction')
            ->andWhere('transaction.companyId = :companyId')
            ->andWhere('transaction.operationGroupId = :operationGroupId')
            ->setParameter('companyId', $companyId)
            ->setParameter('operationGroupId', $operationGroupId)
            ->orderBy('transaction.occurredAt', 'ASC')
            ->addOrderBy('transaction.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return iterable<FinancialTransaction>
     */
    public function iterateByPeriod(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $shopRef = null,
    ): iterable {
        $queryBuilder = $this->createQueryBuilder('transaction')
            ->andWhere('transaction.companyId = :companyId')
            ->andWhere('transaction.occurredAt >= :from')
            ->andWhere('transaction.occurredAt <= :to')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('transaction.occurredAt', 'ASC')
            ->addOrderBy('transaction.id', 'ASC');

        if (null !== $shopRef) {
            $queryBuilder
                ->andWhere('transaction.shopRef = :shopRef')
                ->setParameter('shopRef', $shopRef);
        }

        return $queryBuilder->getQuery()->toIterable();
    }

    /**
     * @return list<FinancialTransaction>
     */
    public function findByRawRecordId(string $companyId, string $rawRecordId): array
    {
        return $this->createQueryBuilder('transaction')
            ->andWhere('transaction.companyId = :companyId')
            ->andWhere('transaction.rawRecordId = :rawRecordId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawRecordId', $rawRecordId)
            ->orderBy('transaction.occurredAt', 'ASC')
            ->addOrderBy('transaction.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
