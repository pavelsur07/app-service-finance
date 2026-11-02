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

    /**
     * Подписки кабинетов одним запросом (с тарифом), ключ — companyId.
     *
     * @companyScopeExempt выборка по явному списку кабинетов (админка, страница пользователей): ограничение — сам список
     *
     * @param list<string> $companyIds
     *
     * @return array<string, CompanySubscription>
     */
    public function findByCompanyIds(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }

        /** @var list<CompanySubscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->addSelect('t')
            ->join('s.tariff', 't')
            ->andWhere('s.companyId IN (:companyIds)')
            ->setParameter('companyIds', array_values(array_unique($companyIds)))
            ->getQuery()
            ->getResult();

        $byCompany = [];
        foreach ($rows as $subscription) {
            $byCompany[$subscription->getCompanyId()] = $subscription;
        }

        return $byCompany;
    }
}
