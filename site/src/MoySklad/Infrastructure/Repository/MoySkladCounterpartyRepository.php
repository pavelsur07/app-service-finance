<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Repository;

use App\MoySklad\Entity\MoySkladCounterparty;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MoySkladCounterparty> */
final class MoySkladCounterpartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoySkladCounterparty::class);
    }

    public function findByIdAndCompanyId(string $id, string $companyId): ?MoySkladCounterparty
    {
        return $this->findOneBy(['companyId' => $companyId, 'id' => $id]);
    }

    public function findByExternalId(string $companyId, string $connectionId, string $externalId): ?MoySkladCounterparty
    {
        return $this->findOneBy(['companyId' => $companyId, 'connectionId' => $connectionId, 'externalId' => $externalId]);
    }
}
