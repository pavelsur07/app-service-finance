<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\Subscription;
use App\Billing\Entity\SubscriptionIntegration;
use App\Billing\Enum\SubscriptionIntegrationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class SubscriptionIntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionIntegration::class);
    }

    /**
     * @return SubscriptionIntegration[]
     */
    public function findActiveForSubscription(Subscription $subscription): array
    {
        return $this->createQueryBuilder('subscriptionIntegration')
            ->andWhere('subscriptionIntegration.subscription = :subscription')
            ->andWhere('subscriptionIntegration.status = :status')
            ->setParameter('subscription', $subscription)
            ->setParameter('status', SubscriptionIntegrationStatus::ACTIVE)
            ->orderBy('subscriptionIntegration.startedAt', 'ASC')
            ->addOrderBy('subscriptionIntegration.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function isIntegrationEnabled(Subscription $subscription, string $integrationCode): bool
    {
        $result = $this->createQueryBuilder('subscriptionIntegration')
            ->select('1')
            ->innerJoin('subscriptionIntegration.integration', 'integration')
            ->andWhere('subscriptionIntegration.subscription = :subscription')
            ->andWhere('subscriptionIntegration.status = :status')
            ->andWhere('integration.code = :code')
            ->setParameter('subscription', $subscription)
            ->setParameter('status', SubscriptionIntegrationStatus::ACTIVE)
            ->setParameter('code', $integrationCode)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $result;
    }
}
