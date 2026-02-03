<?php

namespace App\Deals\Repository;

use App\Company\Entity\Company;
use App\Deals\Entity\Deal;
use App\Deals\Service\DealFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<Deal>
 */
class DealRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deal::class);
    }

    public function findOneByIdForCompany(UuidInterface $id, Company $company): ?Deal
    {
        return $this->createQueryBuilder('deal')
            ->andWhere('deal.id = :id')
            ->andWhere('deal.company = :company')
            ->setParameter('id', $id->toString())
            ->setParameter('company', $company)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Pagerfanta<Deal>
     */
    public function findListForCompany(Company $company, DealFilter $filter): Pagerfanta
    {
        $allowedLimits = [20, 30, 50];
        $limit = in_array($filter->limit, $allowedLimits, true) ? $filter->limit : $allowedLimits[0];
        $page = max(1, $filter->page);

        $qb = $this->createQueryBuilder('deal')
            ->andWhere('deal.company = :company')
            ->setParameter('company', $company)
            ->orderBy('deal.recognizedAt', 'DESC')
            ->addOrderBy('deal.createdAt', 'DESC');

        if ($filter->dateFrom) {
            $qb->andWhere('deal.recognizedAt >= :dateFrom')
                ->setParameter(
                    'dateFrom',
                    \DateTimeImmutable::createFromInterface($filter->dateFrom),
                    Types::DATE_IMMUTABLE
                );
        }

        if ($filter->dateTo) {
            $qb->andWhere('deal.recognizedAt <= :dateTo')
                ->setParameter(
                    'dateTo',
                    \DateTimeImmutable::createFromInterface($filter->dateTo),
                    Types::DATE_IMMUTABLE
                );
        }

        if ($filter->status) {
            $qb->andWhere('deal.status = :status')
                ->setParameter('status', $filter->status);
        }

        if ($filter->channel) {
            $qb->andWhere('deal.channel = :channel')
                ->setParameter('channel', $filter->channel);
        }

        if ($filter->counterparty) {
            $qb->andWhere('deal.counterparty = :counterparty')
                ->setParameter('counterparty', $filter->counterparty);
        }

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        return $pager;
    }
}
