<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdDocumentLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class AdDocumentLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdDocumentLine::class);
    }

    public function save(AdDocumentLine $line): void
    {
        $this->getEntityManager()->persist($line);
    }

    /**
     * @return AdDocumentLine[]
     */
    public function findByAdDocumentId(string $adDocumentId): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.adDocument = :adDocumentId')
            ->setParameter('adDocumentId', $adDocumentId)
            ->getQuery()
            ->getResult();
    }
}
