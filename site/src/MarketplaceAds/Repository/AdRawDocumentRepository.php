<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdRawDocumentRepository extends ServiceEntityRepository
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
     * Количество AdRawDocument компании по площадке в диапазоне дат включительно.
     *
     * Используется для финализации AdLoadJob: condition «все документы обработаны» —
     * это `COUNT(raw) == processed_days + failed_days`. Счётчик `loaded_days` в
     * AdLoadJob coverage-based (chunkDays) и может overshoot'ить при retry
     * оркестратора, поэтому использовать его в условии финализации нельзя.
     * COUNT по AdRawDocument идемпотентен благодаря UniqueConstraint
     * (company_id, marketplace, report_date): повторный upsert за ту же дату
     * не создаст новую строку.
     */
    public function countByCompanyMarketplaceAndDateRange(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): int {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.companyId = :companyId')
            ->andWhere('r.marketplace = :marketplace')
            ->andWhere('r.reportDate BETWEEN :from AND :to')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult();
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
