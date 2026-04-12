<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class AdDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdDocument::class);
    }

    public function save(AdDocument $document): void
    {
        $this->getEntityManager()->persist($document);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?AdDocument
    {
        return $this->findOneBy([
            'id'        => $id,
            'companyId' => $companyId,
        ]);
    }

    public function deleteByRawDocumentId(string $companyId, string $adRawDocumentId): void
    {
        $this->createQueryBuilder('d')
            ->delete()
            ->where('d.companyId = :companyId')
            ->andWhere('d.adRawDocumentId = :adRawDocumentId')
            ->setParameter('companyId', $companyId)
            ->setParameter('adRawDocumentId', $adRawDocumentId)
            ->getQuery()
            ->execute();
    }
}
