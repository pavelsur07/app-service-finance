<?php

namespace App\Marketplace\Wildberries\Repository;

use App\Entity\Company;
use App\Marketplace\Wildberries\Entity\WildberriesReportDetail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WildberriesReportDetailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WildberriesReportDetail::class);
    }

    public function findOneByCompanyAndRrdId(Company $company, int $rrdId): ?WildberriesReportDetail
    {
        return $this->createQueryBuilder('wrd')
            ->andWhere('wrd.company = :company')
            ->andWhere('wrd.rrdId = :rrdId')
            ->setParameter('company', $company)
            ->setParameter('rrdId', $rrdId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function upsert(WildberriesReportDetail $row): void
    {
        $em = $this->getEntityManager();
        $em->persist($row);
        $em->flush();
    }

    public function hasDetailsForCompany(Company $company): bool
    {
        $count = $this->createQueryBuilder('wrd')
            ->select('COUNT(wrd.id)')
            ->andWhere('wrd.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function findLatestSaleDt(Company $company): ?\DateTimeImmutable
    {
        $qb = $this->createQueryBuilder('wrd')
            ->select('MAX(wrd.saleDt) as maxSaleDt')
            ->andWhere('wrd.company = :company')
            ->andWhere('wrd.saleDt IS NOT NULL')
            ->setParameter('company', $company)
            ->setMaxResults(1);

        $result = $qb->getQuery()->getSingleScalarResult();

        return $result ? new \DateTimeImmutable($result) : null;
    }

    public function findOldestOpenSaleDt(Company $company): ?\DateTimeImmutable
    {
        // «Открытыми» считаем строки без rrDt — по аналогии с продажами, опираемся на существующие поля.
        $qb = $this->createQueryBuilder('wrd')
            ->select('MIN(wrd.saleDt) as minSaleDt')
            ->andWhere('wrd.company = :company')
            ->andWhere('wrd.saleDt IS NOT NULL')
            ->andWhere('wrd.rrDt IS NULL')
            ->setParameter('company', $company)
            ->setMaxResults(1);

        $result = $qb->getQuery()->getSingleScalarResult();

        return $result ? new \DateTimeImmutable($result) : null;
    }
}
