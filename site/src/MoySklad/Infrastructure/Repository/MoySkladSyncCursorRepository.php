<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Repository;

use App\MoySklad\Entity\MoySkladSyncCursor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MoySkladSyncCursor> */
final class MoySkladSyncCursorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoySkladSyncCursor::class);
    }

    public function findFor(string $companyId, string $connectionId, string $entityType): ?MoySkladSyncCursor
    {
        return $this->findOneBy(['companyId' => $companyId, 'connectionId' => $connectionId, 'entityType' => $entityType]);
    }
}
