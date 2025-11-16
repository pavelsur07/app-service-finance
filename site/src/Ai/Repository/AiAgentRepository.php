<?php

declare(strict_types=1);

namespace App\Ai\Repository;

use App\Ai\Entity\AiAgent;
use App\Ai\Enum\AiAgentType;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiAgent>
 */
final class AiAgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiAgent::class);
    }

    public function findOneByCompanyAndType(Company $company, AiAgentType $type): ?AiAgent
    {
        return $this->createQueryBuilder('agent')
            ->andWhere('agent.company = :company')
            ->andWhere('agent.type = :type')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<AiAgent>
     */
    public function findEnabledByType(AiAgentType $type): array
    {
        return $this->createQueryBuilder('agent')
            ->andWhere('agent.type = :type')
            ->andWhere('agent.isEnabled = :enabled')
            ->setParameter('type', $type)
            ->setParameter('enabled', true)
            ->getQuery()
            ->getResult();
    }
}
