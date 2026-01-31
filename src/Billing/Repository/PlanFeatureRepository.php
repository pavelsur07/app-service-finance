<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\Plan;
use App\Billing\Entity\PlanFeature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PlanFeatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanFeature::class);
    }

    /**
     * @return PlanFeature[]
     */
    public function findByPlan(Plan $plan): array
    {
        return $this->createQueryBuilder('planFeature')
            ->andWhere('planFeature.plan = :plan')
            ->setParameter('plan', $plan)
            ->getQuery()
            ->getResult();
    }

    public function findOneByPlanAndFeatureCode(Plan $plan, string $featureCode): ?PlanFeature
    {
        return $this->createQueryBuilder('planFeature')
            ->innerJoin('planFeature.feature', 'feature')
            ->andWhere('planFeature.plan = :plan')
            ->andWhere('feature.code = :featureCode')
            ->setParameter('plan', $plan)
            ->setParameter('featureCode', $featureCode)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
