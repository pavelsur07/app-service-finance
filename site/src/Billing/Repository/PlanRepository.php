<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\Plan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plan::class);
    }

    public function findOneByCode(string $code): ?Plan
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findFirstActive(): ?Plan
    {
        return $this->createQueryBuilder('plan')
            ->andWhere('plan.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('plan.priceAmount', 'ASC')
            ->addOrderBy('plan.code', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Plan[]
     */
    public function findAllActiveOrdered(): array
    {
        return $this->createQueryBuilder('plan')
            ->andWhere('plan.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('plan.priceAmount', 'ASC')
            ->addOrderBy('plan.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Plan[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('plan')
            ->orderBy('plan.createdAt', 'DESC')
            ->addOrderBy('plan.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneById(string $id): ?Plan
    {
        return $this->find($id);
    }
}
