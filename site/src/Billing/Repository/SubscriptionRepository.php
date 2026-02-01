<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\Subscription;
use App\Billing\Enum\SubscriptionStatus;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findCurrentForCompany(Company $company, ?\DateTimeImmutable $now = null): ?Subscription
    {
        return $this->createQueryBuilder('subscription')
            ->andWhere('subscription.company = :company')
            ->andWhere('subscription.status IN (:statuses)')
            ->andWhere('subscription.currentPeriodEnd >= :now')
            ->setParameter('company', $company)
            ->setParameter('statuses', [
                SubscriptionStatus::ACTIVE,
                SubscriptionStatus::TRIAL,
                SubscriptionStatus::GRACE,
            ])
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->addOrderBy(
                'CASE '
                .'WHEN subscription.status = :active THEN 1 '
                .'WHEN subscription.status = :trial THEN 2 '
                .'WHEN subscription.status = :grace THEN 3 '
                .'ELSE 4 END'
            )
            ->addOrderBy('subscription.currentPeriodEnd', 'DESC')
            ->setParameter('active', SubscriptionStatus::ACTIVE)
            ->setParameter('trial', SubscriptionStatus::TRIAL)
            ->setParameter('grace', SubscriptionStatus::GRACE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
