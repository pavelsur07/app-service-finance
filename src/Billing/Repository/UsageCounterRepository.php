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
        return $this->findOneBy([
            'company' => $company,
            'periodKey' => $periodKey,
            'metric' => $metric,
        ]);
    }

    public function upsertAdjust(Company $company, string $periodKey, string $metric, int $by): void
    {
        $connection = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
INSERT INTO billing_usage_counter (id, company_id, period_key, metric, used)
VALUES (:id, :company_id, :period_key, :metric, :used)
ON CONFLICT (company_id, period_key, metric)
DO UPDATE SET used = billing_usage_counter.used + EXCLUDED.used
SQL;

        $connection->executeStatement(
            $sql,
            [
                'id' => Uuid::uuid4()->toString(),
                'company_id' => $company->getId(),
                'period_key' => $periodKey,
                'metric' => $metric,
                'used' => $by,
            ],
            [
                'id' => Types::GUID,
                'company_id' => Types::GUID,
                'period_key' => Types::STRING,
                'metric' => Types::STRING,
                'used' => Types::BIGINT,
            ],
        );
    }
}
