<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\CompanySubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanySubscription>
 */
final class CompanySubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanySubscription::class);
    }

    public function findOneByCompanyId(string $companyId): ?CompanySubscription
    {
        return $this->findOneBy(['companyId' => $companyId]);
    }
}
