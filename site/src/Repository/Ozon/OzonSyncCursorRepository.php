<?php

namespace App\Repository\Ozon;

use App\Entity\Company;
use App\Marketplace\Ozon\Entity\OzonSyncCursor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OzonSyncCursorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonSyncCursor::class);
    }

    public function findOneByCompanyAndScheme(Company $company, string $scheme): ?OzonSyncCursor
    {
        return $this->findOneBy([
            'company' => $company,
            'scheme' => $scheme,
        ]);
    }
}
