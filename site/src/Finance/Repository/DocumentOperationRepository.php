<?php

declare(strict_types=1);

namespace App\Finance\Repository;

use App\Finance\Entity\DocumentOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DocumentOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentOperation::class);
    }
}
