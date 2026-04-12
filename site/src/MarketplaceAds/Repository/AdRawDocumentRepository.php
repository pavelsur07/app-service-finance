<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class AdRawDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdRawDocument::class);
    }

    public function save(AdRawDocument $document): void
    {
        $this->getEntityManager()->persist($document);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?AdRawDocument
    {
        return $this->findOneBy([
            'id'        => $id,
            'companyId' => $companyId,
        ]);
    }

    public function findByMarketplaceAndDate(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $reportDate,
    ): ?AdRawDocument {
        return $this->findOneBy([
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'reportDate'  => $reportDate,
        ]);
    }

    /**
     * @return AdRawDocument[]
     */
    public function findDrafts(string $companyId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.companyId = :companyId')
            ->andWhere('r.status = :status')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', AdRawDocumentStatus::DRAFT)
            ->orderBy('r.reportDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Поиск raw-документов по произвольной комбинации фильтров. Все параметры опциональны:
     * любой из них может быть null — тогда ограничение по этому полю не накладывается.
     * Используется reprocess-командой для массовой переобработки.
     *
     * @return AdRawDocument[]
     */
    public function findByFilters(
        ?string $companyId = null,
        ?string $marketplace = null,
        ?\DateTimeImmutable $reportDate = null,
    ): array {
        $qb = $this->createQueryBuilder('r')->orderBy('r.reportDate', 'DESC');

        if ($companyId !== null) {
            $qb->andWhere('r.companyId = :companyId')->setParameter('companyId', $companyId);
        }

        if ($marketplace !== null) {
            $qb->andWhere('r.marketplace = :marketplace')->setParameter('marketplace', $marketplace);
        }

        if ($reportDate !== null) {
            $qb->andWhere('r.reportDate = :reportDate')->setParameter('reportDate', $reportDate);
        }

        return $qb->getQuery()->getResult();
    }
}
