<?php

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

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
            ->andWhere('t.deletedAt IS NULL')
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
            ->andWhere('t.deletedAt IS NULL')
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
            ->andWhere('t.deletedAt IS NULL')
            ->setMaxResults(1)
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function paginateDeletedByCompany(string $companyId, int $page, int $perPage): Pagerfanta
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->andWhere('t.deletedAt IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->orderBy('t.deletedAt', 'DESC');

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($perPage);
        $pager->setAllowOutOfRangePages(true);
        $pager->setCurrentPage($page);

        return $pager;
    }

    public function paginateByCompanyWithFilters(
        Company $company,
        array $filters,
        int $page,
        int $perPage,
    ): Pagerfanta {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('t.occurredAt', 'DESC');

        if ($filters['dateFrom']) {
            $qb->andWhere('t.occurredAt >= :df')->setParameter('df', new \DateTimeImmutable($filters['dateFrom']));
        }
        if ($filters['dateTo']) {
            $qb->andWhere('t.occurredAt <= :dt')->setParameter('dt', new \DateTimeImmutable($filters['dateTo']));
        }
        if ($filters['accountId']) {
            $qb->andWhere('t.moneyAccount = :acc')->setParameter('acc', $filters['accountId']);
        }
        if ($filters['categoryId']) {
            $qb->andWhere('t.cashflowCategory = :cat')->setParameter('cat', $filters['categoryId']);
        }
        if ($filters['counterpartyId']) {
            $qb->andWhere('t.counterparty = :cp')->setParameter('cp', $filters['counterpartyId']);
        }
        if ($filters['direction']) {
            $qb->andWhere('t.direction = :dir')->setParameter('dir', $filters['direction']);
        }
        if ($filters['amountMin']) {
            $qb->andWhere('t.amount >= :amin')->setParameter('amin', $filters['amountMin']);
        }
        if ($filters['amountMax']) {
            $qb->andWhere('t.amount <= :amax')->setParameter('amax', $filters['amountMax']);
        }
        if ($filters['q']) {
            $qb->andWhere('t.description LIKE :q')->setParameter('q', '%'.$filters['q'].'%');
        }

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($perPage);
        $pager->setAllowOutOfRangePages(true);
        $pager->setCurrentPage($page);

        return $pager;
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
            ->andWhere('t.deletedAt IS NULL')
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
