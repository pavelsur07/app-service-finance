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
     * Deterministic lookup for daily sync: returns all exact-day active documents
     * (processingStatus is null or not FAILED) in canonical order:
     * completed first, then COALESCE(processedAt, syncedAt) DESC, then id DESC.
     *
     * @return list<MarketplaceRawDocument>
     */
    public function findActiveExactDayDocuments(
        Company $company,
        MarketplaceType $marketplace,
        string $documentType,
        \DateTimeImmutable $day,
    ): array {
        return $this->createQueryBuilder('d')
            ->addSelect('CASE WHEN d.processingStatus = :completed THEN 0 ELSE 1 END AS HIDDEN completed_rank')
            ->addSelect('COALESCE(d.processedAt, d.syncedAt) AS HIDDEN canonical_at')
            ->where('d.company = :company')
            ->andWhere('d.marketplace = :marketplace')
            ->andWhere('d.documentType = :documentType')
            ->andWhere('d.periodFrom = :day')
            ->andWhere('d.periodTo = :day')
            ->andWhere('(d.processingStatus IS NULL OR d.processingStatus != :failed)')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('documentType', $documentType)
            ->setParameter('day', $day)
            ->setParameter('failed', PipelineStatus::FAILED)
            ->setParameter('completed', PipelineStatus::COMPLETED)
            ->orderBy('completed_rank', 'ASC')
            ->addOrderBy('canonical_at', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Deterministic lookup for refresh/idempotency by exact period + api endpoint.
     * Excludes FAILED documents and returns newest first.
     *
     * @return list<MarketplaceRawDocument>
     */
    public function findActiveExactPeriodDocuments(
        Company $company,
        MarketplaceType $marketplace,
        string $documentType,
        string $apiEndpoint,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ): array {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.marketplace = :marketplace')
            ->andWhere('d.documentType = :documentType')
            ->andWhere('d.apiEndpoint = :apiEndpoint')
            ->andWhere('d.periodFrom = :periodFrom')
            ->andWhere('d.periodTo = :periodTo')
            ->andWhere('(d.processingStatus IS NULL OR d.processingStatus != :failed)')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('documentType', $documentType)
            ->setParameter('apiEndpoint', $apiEndpoint)
            ->setParameter('periodFrom', $periodFrom)
            ->setParameter('periodTo', $periodTo)
            ->setParameter('failed', PipelineStatus::FAILED)
            ->orderBy('d.syncedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Idempotency lookup for refresh/idempotency by exact period + api endpoint.
     */
    public function findActiveExactPeriodDocument(
        Company $company,
        MarketplaceType $marketplace,
        string $documentType,
        string $apiEndpoint,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ): ?MarketplaceRawDocument {
        return $this->findActiveExactPeriodDocuments(
            $company,
            $marketplace,
            $documentType,
            $apiEndpoint,
            $periodFrom,
            $periodTo,
        )[0] ?? null;
    }

    public function findMinPeriodFromForSuccessfulDocuments(
        Company $company,
        MarketplaceType $marketplace,
        string $documentType,
        string $apiEndpoint,
        \DateTimeImmutable $yearStart,
        \DateTimeImmutable $yesterday,
    ): ?\DateTimeImmutable {
        $value = $this->createQueryBuilder('d')
            ->select('MIN(d.periodFrom) AS min_period_from')
            ->where('d.company = :company')
            ->andWhere('d.marketplace = :marketplace')
            ->andWhere('d.documentType = :documentType')
            ->andWhere('d.apiEndpoint = :apiEndpoint')
            ->andWhere('d.periodFrom >= :yearStart')
            ->andWhere('d.periodFrom <= :yesterday')
            ->andWhere('d.processingStatus = :completed')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('documentType', $documentType)
            ->setParameter('apiEndpoint', $apiEndpoint)
            ->setParameter('yearStart', $yearStart)
            ->setParameter('yesterday', $yesterday)
            ->setParameter('completed', PipelineStatus::COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        if (!is_string($value) || '' === $value) {
            return null;
        }

        return new \DateTimeImmutable($value.' 00:00:00');
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
     * Найти raw-документы за период для переобработки.
     *
     * Используется в ReprocessMarketplaceCommand.
     * Фильтрует по periodFrom/periodTo документа (перекрытие с запрошенным периодом).
     *
     * @param string|null $documentType null = все типы | 'sales_report' | 'realization'
     *
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

        if (null !== $documentType) {
            $qb->andWhere('d.documentType = :documentType')
                ->setParameter('documentType', $documentType);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Найти COMPLETED raw-документы, у которых marketplace_costs
     * ссылаются на категории другой компании.
     *
     * @companyScopeExempt Метод сам и есть детектор межкомпанейских утечек:
     *                     он ищет затраты, у которых company_id не совпадает с
     *                     company_id их категории. Обязательное ограничение
     *                     одной компанией лишило бы его смысла — по всему
     *                     арендатору такое расхождение и надо искать, а
     *                     запускает его администратор из консоли. Сужение до
     *                     одной компании остаётся доступным: параметр
     *                     $companyId применяется, когда он передан, и
     *                     ReprocessMarketplaceCostsCommand пробрасывает туда
     *                     опцию --company-id.
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

        if (null !== $companyId) {
            $sql .= ' AND mrd.company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        $ids = $conn->fetchFirstColumn($sql, $params);

        if ([] === $ids) {
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
        $lastDay = $firstDay->modify('last day of this month');

        return $this->createQueryBuilder('d')
            ->join('d.company', 'c')
            ->where('c.id = :companyId')
            ->andWhere('d.marketplace = :marketplace')
            ->andWhere('d.documentType = :documentType')
            ->andWhere('d.periodFrom >= :firstDay')
            ->andWhere('d.periodTo <= :lastDay')
            ->andWhere('(d.processingStatus IS NULL OR d.processingStatus != :loading)')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('documentType', 'sales_report')
            ->setParameter('firstDay', $firstDay)
            ->setParameter('lastDay', $lastDay)
            ->setParameter('loading', PipelineStatus::LOADING)
            ->orderBy('d.periodFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
