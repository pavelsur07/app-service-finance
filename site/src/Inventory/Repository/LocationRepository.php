<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\Location;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    /**
     * @param list<string> $externalIds
     *
     * @return array<string, Location>
     */
    public function findByCompanySourceAndExternalIds(string $companyId, MarketplaceType $source, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }

        $locations = $this->createQueryBuilder('location')
            ->where('location.companyId = :companyId')
            ->andWhere('location.externalSystem = :source')
            ->andWhere('location.externalId IN (:externalIds)')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
            ->setParameter('externalIds', array_values(array_unique($externalIds)))
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($locations as $location) {
            if (null !== $location->getExternalId()) {
                $result[$location->getExternalId()] = $location;
            }
        }

        return $result;
    }
}
