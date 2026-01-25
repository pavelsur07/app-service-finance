<?php

declare(strict_types=1);

namespace App\Ai\Repository;

use App\Ai\Entity\AiRun;
use App\Ai\Entity\AiSuggestion;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiSuggestion>
 */
final class AiSuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiSuggestion::class);
    }

    public function save(AiSuggestion $suggestion, bool $flush = false): void
    {
        $this->_em->persist($suggestion);

        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * @return list<AiSuggestion>
     */
    public function findLatestForCompany(Company $company, int $limit = 20): array
    {
        return $this->createQueryBuilder('suggestion')
            ->andWhere('suggestion.company = :company')
            ->orderBy('suggestion.createdAt', 'DESC')
            ->setParameter('company', $company)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countForRun(AiRun $run): int
    {
        return (int) $this->createQueryBuilder('suggestion')
            ->select('COUNT(suggestion.id)')
            ->andWhere('suggestion.run = :run')
            ->setParameter('run', $run)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
