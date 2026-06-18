<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\SystemCounterparty;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\SystemCounterpartyNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemCounterparty>
 */
final class SystemCounterpartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemCounterparty::class);
    }

    public function findBySource(IngestSource $source): ?SystemCounterparty
    {
        return $this->createQueryBuilder('counterparty')
            ->andWhere('counterparty.source = :source')
            ->setParameter('source', $source->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getBySource(IngestSource $source): SystemCounterparty
    {
        $counterparty = $this->findBySource($source);
        if (null === $counterparty) {
            throw new SystemCounterpartyNotFoundException(sprintf('System counterparty for source "%s" was not found.', $source->value));
        }

        return $counterparty;
    }
}
