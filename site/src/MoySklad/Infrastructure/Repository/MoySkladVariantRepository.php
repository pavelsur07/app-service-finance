<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Repository;

use App\MoySklad\Entity\MoySkladVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MoySkladVariant> */
final class MoySkladVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoySkladVariant::class);
    }

    public function findByExternalId(string $companyId, string $connectionId, string $externalId): ?MoySkladVariant
    {
        return $this->findOneBy(['companyId' => $companyId, 'connectionId' => $connectionId, 'externalId' => $externalId]);
    }

    /** @param list<string> $externalIds
     * @return array<string, MoySkladVariant>
     */
    public function findByExternalIds(string $companyId, string $connectionId, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }
        $rows = $this->findBy(['companyId' => $companyId, 'connectionId' => $connectionId, 'externalId' => $externalIds]);
        $found = [];
        foreach ($rows as $row) {
            $found[$row->getExternalId()] = $row;
        }

        return $found;
    }
}
