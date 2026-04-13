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
            'id' => $id,
            'companyId' => $companyId,
        ]);
    }

    public function findByMarketplaceAndDate(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $reportDate,
    ): ?AdRawDocument {
        return $this->findOneBy([
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'reportDate' => $reportDate,
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
     * Стримит raw-документы по произвольной комбинации фильтров. Все параметры опциональны:
     * любой из них может быть null — тогда ограничение по этому полю не накладывается.
     * Используется reprocess-командой для массовой переобработки.
     *
     * Реализовано через {@see \Doctrine\ORM\AbstractQuery::toIterable()} — Doctrine
     * читает строки из курсора БД по одной, не материализуя весь результат в памяти.
     * Это одновременно:
     *  - ограничивает peak memory usage при `reprocess --all` с десятками тысяч документов;
     *  - позволяет команде безопасно вызывать EntityManager::clear() между батчами —
     *    следующая yield-нутая entity будет свежей managed-entity, а не detached-объектом
     *    из предзагруженного массива (см. issue #1495/codex-review P1).
     *
     * @return iterable<AdRawDocument>
     */
    public function streamByFilters(
        ?string $companyId = null,
        ?string $marketplace = null,
        ?\DateTimeImmutable $reportDate = null,
    ): iterable {
        $qb = $this->createQueryBuilder('r')->orderBy('r.reportDate', 'DESC');

        if (null !== $companyId) {
            $qb->andWhere('r.companyId = :companyId')->setParameter('companyId', $companyId);
        }

        if (null !== $marketplace) {
            $qb->andWhere('r.marketplace = :marketplace')->setParameter('marketplace', $marketplace);
        }

        if (null !== $reportDate) {
            $qb->andWhere('r.reportDate = :reportDate')->setParameter('reportDate', $reportDate);
        }

        return $qb->getQuery()->toIterable();
    }
}
