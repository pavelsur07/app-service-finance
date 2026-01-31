<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\Integration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class IntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Integration::class);
    }

    public function findOneByCode(string $code): ?Integration
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @return Integration[]
     */
    public function findAllActiveOrdered(): array
    {
        return $this->createQueryBuilder('integration')
            ->andWhere('integration.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('integration.name', 'ASC')
            ->addOrderBy('integration.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
