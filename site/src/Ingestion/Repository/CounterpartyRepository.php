<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\Counterparty;
use App\Ingestion\Enum\IngestSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Counterparty>
 */
final class CounterpartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Counterparty::class);
    }

    public function findByNaturalKey(string $companyId, IngestSource $source, string $externalKey): ?Counterparty
    {
        return $this->createQueryBuilder('counterparty')
            ->andWhere('counterparty.companyId = :companyId')
            ->andWhere('counterparty.source = :source')
            ->andWhere('counterparty.externalKey = :externalKey')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('externalKey', $externalKey)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getOrCreate(
        string $companyId,
        IngestSource $source,
        string $externalKey,
        string $name,
    ): Counterparty {
        $counterparty = $this->findByNaturalKey($companyId, $source, $externalKey);
        if (null !== $counterparty) {
            $counterparty->rename($name);

            return $counterparty;
        }

        $counterparty = new Counterparty($companyId, $source, $externalKey, $name);
        $this->getEntityManager()->persist($counterparty);

        return $counterparty;
    }
}
