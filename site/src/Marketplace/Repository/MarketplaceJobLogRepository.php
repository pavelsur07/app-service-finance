<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceJobLog;
use App\Marketplace\Enum\JobType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceJobLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceJobLog::class);
    }

    /**
     * Найти последнюю запись для каждого из указанных типов задач.
     *
     * @param  JobType[]                      $jobTypes
     * @return array<string, MarketplaceJobLog>  ['job_type_value' => MarketplaceJobLog]
     */
    public function findLastByJobTypes(string $companyId, array $jobTypes): array
    {
        if (empty($jobTypes)) {
            return [];
        }

        $logs = $this->createQueryBuilder('j')
            ->where('j.companyId = :companyId')
            ->andWhere('j.jobType IN (:jobTypes)')
            ->setParameter('companyId', $companyId)
            ->setParameter('jobTypes', $jobTypes)
            ->orderBy('j.startedAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Берём только последнюю запись по каждому типу
        $result = [];
        foreach ($logs as $log) {
            $key = $log->getJobType()->value;
            if (!isset($result[$key])) {
                $result[$key] = $log;
            }
        }

        return $result;
    }

    public function save(MarketplaceJobLog $log): void
    {
        $this->getEntityManager()->persist($log);
        $this->getEntityManager()->flush();
    }
}
