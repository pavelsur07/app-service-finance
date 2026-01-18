<?php

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Entity\Company;
use App\Enum\CashDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CashTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashTransaction::class);
    }

    /**
     * @return list<array{date:string,inflow:string,outflow:string}>
     */
    public function sumByDay(Company $company, MoneyAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select(
                't.occurredAt as date',
                "SUM(CASE WHEN t.direction = 'INFLOW' THEN t.amount ELSE 0 END) as inflow",
                "SUM(CASE WHEN t.direction = 'OUTFLOW' THEN t.amount ELSE 0 END) as outflow"
            )
            ->where('t.company = :company')
            ->andWhere('t.moneyAccount = :account')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('account', $account)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->groupBy('date')
            ->orderBy('date', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function existsByCompanyAndDedupe(string $companyId, string $dedupeHash): bool
    {
        return (bool) $this->createQueryBuilder('t')
            ->select('1')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->andWhere('t.dedupeHash = :dedupeHash')
            ->setMaxResults(1)
            ->setParameter('companyId', $companyId)
            ->setParameter('dedupeHash', $dedupeHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByImport(string $companyId, string $source, string $externalId): ?CashTransaction
    {
        return $this->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->andWhere('t.importSource = :source')
            ->andWhere('t.externalId = :externalId')
            ->setMaxResults(1)
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<string> $accountIds
     *
     * @return array<string, array{inflow: string, outflow: string}>
     */
    public function sumByAccountAndPeriod(Company $company, array $accountIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $accountIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('t')
            ->select(
                'IDENTITY(t.moneyAccount) as accountId',
                'SUM(CASE WHEN t.direction = :inflow THEN t.amount ELSE 0 END) as inflow',
                'SUM(CASE WHEN t.direction = :outflow THEN t.amount ELSE 0 END) as outflow'
            )
            ->join('t.moneyAccount', 'ma')
            ->where('t.company = :company')
            ->andWhere('IDENTITY(t.moneyAccount) IN (:accountIds)')
            ->andWhere('t.currency = ma.currency')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->groupBy('accountId')
            ->setParameter('company', $company)
            ->setParameter('accountIds', $accountIds)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('inflow', CashDirection::INFLOW)
            ->setParameter('outflow', CashDirection::OUTFLOW);

        // TODO: exclude transfers (isTransfer = true) if the report should show only operational turnovers.

        $result = $qb->getQuery()->getArrayResult();

        $byAccountId = [];
        foreach ($result as $row) {
            $accountId = (string) $row['accountId'];
            $byAccountId[$accountId] = [
                'inflow' => bcadd((string) ($row['inflow'] ?? '0'), '0', 2),
                'outflow' => bcadd((string) ($row['outflow'] ?? '0'), '0', 2),
            ];
        }

        return $byAccountId;
    }
}
