<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\Feature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class FeatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feature::class);
    }

    public function findOneByCode(string $code): ?Feature
    {
        return $this->findOneBy(['code' => $code]);
    }
}
