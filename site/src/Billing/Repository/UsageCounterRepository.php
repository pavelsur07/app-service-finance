<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\UsageCounter;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

final class UsageCounterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsageCounter::class);
    }

    public function findOne(Company $company, string $periodKey, string $metric): ?UsageCounter
    {
        return $this->createQueryBuilder('usageCounter')
            ->andWhere('usageCounter.company = :company')
            ->andWhere('usageCounter.periodKey = :periodKey')
            ->andWhere('usageCounter.metric = :metric')
            ->setParameter('company', $company)
            ->setParameter('periodKey', $periodKey)
            ->setParameter('metric', $metric)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function upsertIncrement(Company $company, string $periodKey, string $metric, int $by): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO billing_usage_counter (id, company_id, period_key, metric, used)
            VALUES (:id, :companyId, :periodKey, :metric, :used)
            ON CONFLICT (company_id, period_key, metric)
            DO UPDATE SET used = billing_usage_counter.used + EXCLUDED.used',
            [
                'id' => Uuid::uuid4()->toString(),
                'companyId' => $company->getId(),
                'periodKey' => $periodKey,
                'metric' => $metric,
                'used' => $by,
            ],
            [
                'id' => Types::GUID,
                'companyId' => Types::GUID,
                'periodKey' => Types::STRING,
                'metric' => Types::STRING,
                'used' => Types::INTEGER,
            ],
        );
    }
}
