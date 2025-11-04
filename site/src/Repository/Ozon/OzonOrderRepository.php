<?php

namespace App\Repository\Ozon;

use App\Entity\Company;
use App\Marketplace\Ozon\Entity\OzonOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OzonOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonOrder::class);
    }

    public function findOneByCompanyAndPostingNumber(Company $company, string $postingNumber): ?OzonOrder
    {
        return $this->findOneBy([
            'company' => $company,
            'postingNumber' => $postingNumber,
        ]);
    }
}
