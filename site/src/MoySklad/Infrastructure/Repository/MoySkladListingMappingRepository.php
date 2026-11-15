<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Repository;

use App\MoySklad\Entity\MoySkladListingMappingRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MoySkladListingMappingRevision> */
final class MoySkladListingMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoySkladListingMappingRevision::class);
    }

    public function latest(string $companyId, string $connectionId, string $listingId): ?MoySkladListingMappingRevision
    {
        return $this->findOneBy(['companyId' => $companyId, 'connectionId' => $connectionId, 'listingId' => $listingId], ['revision' => 'DESC']);
    }

    public function acceptedRequest(string $companyId, string $requestId): ?MoySkladListingMappingRevision
    {
        return $this->findOneBy(['companyId' => $companyId, 'requestId' => $requestId]);
    }

    public function append(MoySkladListingMappingRevision $revision): void
    {
        $this->getEntityManager()->persist($revision);
    }
}
