<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestionCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<IngestionCredential>
 */
final class IngestionCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IngestionCredential::class);
    }

    public function findOneByCompanyRefAndType(string $companyId, string $connectionRef, string $type): ?IngestionCredential
    {
        Assert::uuid($companyId);

        return $this->createQueryBuilder('credential')
            ->andWhere('credential.companyId = :companyId')
            ->andWhere('credential.connectionRef = :connectionRef')
            ->andWhere('credential.type = :type')
            ->setParameter('companyId', $companyId)
            ->setParameter('connectionRef', $connectionRef)
            ->setParameter('type', $type)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
