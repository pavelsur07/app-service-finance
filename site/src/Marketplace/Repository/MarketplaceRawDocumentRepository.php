<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceRawDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceRawDocument::class);
    }

    /**
     * @return MarketplaceRawDocument[]
     */
    public function findByCompany(Company $company, int $limit = 20): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.syncedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти все raw-документы компании по маркетплейсу (без фильтра периода).
     *
     * @return MarketplaceRawDocument[]
     */
    public function findByCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
        string $documentType = 'sales_report',
    ): array {
        return $this->createQueryBuilder('d')
            ->join('d.company', 'c')
            ->where('c.id = :companyId')
            ->andWhere('d.marketplace = :marketplace')
            ->andWhere('d.documentType = :documentType')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('documentType', $documentType)
            ->orderBy('d.syncedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти raw-документы за период для переобработки.
     *
     * Используется в ReprocessMarketplaceCommand.
     * Фильтрует по periodFrom/periodTo документа (перекрытие с запрошенным периодом).
     *
     * @param string|null $documentType  null = все типы | 'sales_report' | 'realization'
     * @return MarketplaceRawDocument[]
     */
    public function findByCompanyAndPeriod(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        ?string $documentType = null,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->join('d.company', 'c')
            ->where('c.id = :companyId')
            ->andWhere('d.marketplace = :marketplace')
            // Документ перекрывается с периодом если его конец >= начала запроса
            // и его начало <= конца запроса
            ->andWhere('d.periodFrom <= :periodTo')
            ->andWhere('d.periodTo >= :periodFrom')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('periodFrom', $periodFrom)
            ->setParameter('periodTo', $periodTo)
            ->orderBy('d.syncedAt', 'ASC');

        if ($documentType !== null) {
            $qb->andWhere('d.documentType = :documentType')
                ->setParameter('documentType', $documentType);
        }

    /**
     * Найти raw-документы типа sales_report за конкретный месяц для пакетной обработки.
     *
     * Документ включается если его период полностью входит в запрошенный месяц:
     * periodFrom >= первый день месяца AND periodTo <= последний день месяца.
     *
     * @return MarketplaceRawDocument[]
     */
    public function findForBulkProcessing(
        string $companyId,
        MarketplaceType $marketplace,
        int $year,
        int $month,
    ): array {
        $firstDay = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $lastDay  = $firstDay->modify('last day of this month');

        return $this->createQueryBuilder('d')
            ->join('d.company', 'c')
            ->where('c.id = :companyId')
            ->andWhere('d.marketplace = :marketplace')
            ->andWhere('d.documentType = :documentType')
            ->andWhere('d.periodFrom >= :firstDay')
            ->andWhere('d.periodTo <= :lastDay')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('documentType', 'sales_report')
            ->setParameter('firstDay', $firstDay)
            ->setParameter('lastDay', $lastDay)
            ->orderBy('d.periodFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
