<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\OzonTransactionTotalsCheck;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

final class OzonTransactionTotalsCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonTransactionTotalsCheck::class);
    }

    public function save(OzonTransactionTotalsCheck $check): void
    {
        $this->getEntityManager()->persist($check);
    }

    public function findLatestByCompanyAndPeriod(
        string $companyId,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ): ?OzonTransactionTotalsCheck {
        Assert::uuid($companyId);

        return $this->createQueryBuilder('c')
            ->where('c.companyId = :companyId')
            ->andWhere('c.periodFrom <= :periodTo')
            ->andWhere('c.periodTo >= :periodFrom')
            ->setParameter('companyId', $companyId)
            ->setParameter('periodFrom', $periodFrom)
            ->setParameter('periodTo', $periodTo)
            ->orderBy('c.checkedAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }
}
