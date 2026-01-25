<?php

declare(strict_types=1);

namespace App\Ai\Repository;

use App\Ai\Entity\AiAgent;
use App\Ai\Entity\AiRun;
use App\Ai\Enum\AiRunStatus;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiRun>
 */
final class AiRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiRun::class);
    }

    public function save(AiRun $run, bool $flush = false): void
    {
        $this->_em->persist($run);

        if ($flush) {
            $this->_em->flush();
        }
    }

    public function findLastSuccessfulRun(Company $company): ?AiRun
    {
        return $this->createQueryBuilder('run')
            ->andWhere('run.company = :company')
            ->andWhere('run.status = :status')
            ->setParameter('company', $company)
            ->setParameter('status', AiRunStatus::SUCCESS)
            ->orderBy('run.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestForAgent(AiAgent $agent): ?AiRun
    {
        return $this->createQueryBuilder('run')
            ->andWhere('run.agent = :agent')
            ->orderBy('run.startedAt', 'DESC')
            ->setParameter('agent', $agent)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestPendingRunForAgent(AiAgent $agent): ?AiRun
    {
        return $this->createQueryBuilder('run')
            ->andWhere('run.agent = :agent')
            ->andWhere('run.status = :status')
            ->orderBy('run.startedAt', 'DESC')
            ->setParameter('agent', $agent)
            ->setParameter('status', AiRunStatus::PENDING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
