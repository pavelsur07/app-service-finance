<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceRawDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceRawDocument::class);
    }

    /**
     * Idempotency lookup for daily sync: возвращает существующий raw_document
     * за конкретный день (periodFrom=periodTo), у которого processingStatus
     * НЕ равен FAILED (т.е. null / PENDING / RUNNING / COMPLETED).
     *
     * Используется SyncOzonReportHandler'ом для skip'а повторной загрузки,
     * пока первый прогон ещё in-flight или уже завершён успешно. FAILED
     * документы в выборку не попадают, чтобы retry мог создать новый.
     */
    public function findExistingDayDocument(
        Company $company,
        MarketplaceType $marketplace,
        string $documentType,
        \DateTimeImmutable $day,
    ): ?MarketplaceRawDocument {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.marketplace = :marketplace')
            ->andWhere('d.documentType = :documentType')
            ->andWhere('d.periodFrom = :day')
            ->andWhere('d.periodTo = :day')
            ->andWhere('d.processingStatus IS NULL OR d.processingStatus != :failed')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('documentType', $documentType)
            ->setParameter('day', $day)
            ->setParameter('failed', PipelineStatus::FAILED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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

        return $qb->getQuery()->getResult();
    }

    /**
     * Найти COMPLETED raw-документы, у которых marketplace_costs
     * ссылаются на категории другой компании.
     *
     * @return MarketplaceRawDocument[]
     */
    public function findDocsWithCrossCompanyCosts(?string $companyId = null): array
    {
        /** @var Connection $conn */
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            SELECT mrd.id
            FROM marketplace_raw_documents mrd
            WHERE mrd.processing_status = :status
              AND EXISTS (
                  SELECT 1
                  FROM marketplace_costs mc
                  JOIN marketplace_cost_categories mcc ON mc.category_id = mcc.id
                  WHERE mc.raw_document_id = mrd.id
                    AND mc.company_id != mcc.company_id
              )
            SQL;

        $params = ['status' => PipelineStatus::COMPLETED->value];

        if ($companyId !== null) {
            $sql .= ' AND mrd.company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        $ids = $conn->fetchFirstColumn($sql, $params);

        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('mrd')
            ->addSelect('c')
            ->join('mrd.company', 'c')
            ->where('mrd.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
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
