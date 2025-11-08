<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Entity\ImportLog;
use App\Marketplace\Wildberries\Entity\WildberriesReportDetail;
use App\Service\ActiveCompanyService;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/wb/report_detail', name: 'wb_report_detail_')]
final class WildberriesReportDetailController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ActiveCompanyService $companyContext,
    ) {
    }

    /**
     * ЖУРНАЛ: группы по месяцам (YYYY-MM) из ImportLog по source=wildberries_report_detail.
     * GET /wb/report_detail
     * qparams: page, per_page, source?
     */
    #[Route(path: '', name: 'months', methods: ['GET'])]
    public function months(Request $request): Response
    {
        $company = $this->companyContext->getActiveCompany();

        $source = (string) ($request->query->get('source') ?? 'wildberries_report_detail');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(6, min(24, (int) $request->query->get('per_page', 12)));

        $em = $this->doctrine->getManager();
        $conn = $em->getConnection();

        $sql = <<<SQL
SELECT
  to_char(il.started_at AT TIME ZONE 'UTC', 'YYYY-MM') AS ym,
  MIN(il.started_at) AS started_min,
  MAX(il.finished_at) AS finished_max,
  COUNT(*) AS imports_count,
  SUM(il.created_count) AS created_sum,
  SUM(il.skipped_duplicates) AS skipped_sum,
  SUM(il.errors_count) AS errors_sum
FROM import_log il
WHERE il.company_id = :companyId
  AND il.source = :source
GROUP BY to_char(il.started_at AT TIME ZONE 'UTC', 'YYYY-MM')
ORDER BY ym DESC
LIMIT :limit OFFSET :offset
SQL;

        $rows = $conn->executeQuery(
            $sql,
            [
                'companyId' => $company->getId(),
                'source' => $source,
                'limit' => $perPage,
                'offset' => ($page - 1) * $perPage,
            ],
            [
                'companyId' => \PDO::PARAM_STR,
                'source' => \PDO::PARAM_STR,
                'limit' => \PDO::PARAM_INT,
                'offset' => \PDO::PARAM_INT,
            ]
        )->fetchAllAssociative();

        $countSql = <<<SQL
SELECT COUNT(*) FROM (
  SELECT 1
  FROM import_log il
  WHERE il.company_id = :companyId
    AND il.source = :source
  GROUP BY to_char(il.started_at AT TIME ZONE 'UTC', 'YYYY-MM')
) t
SQL;

        $totalGroups = (int) $conn->executeQuery(
            $countSql,
            ['companyId' => $company->getId(), 'source' => $source],
            ['companyId' => \PDO::PARAM_STR, 'source' => \PDO::PARAM_STR]
        )->fetchOne();

        return $this->render('wb/report_detail/months.html.twig', [
            'company' => $company,
            'groups' => $rows,
            'source' => $source,
            'page' => $page,
            'per_page' => $perPage,
            'total_groups' => $totalGroups,
        ]);
    }

    /**
     * МЕСЯЦ → группы по ISO-неделям внутри месяца.
     * GET /wb/report_detail/{ym}
     * {ym} = YYYY-MM
     */
    #[Route(path: '/{ym}', name: 'month_show', methods: ['GET'], requirements: ['ym' => '\\d{4}-\\d{2}'])]
    public function monthShow(Request $request, string $ym): Response
    {
        $company = $this->companyContext->getActiveCompany();

        $source = (string) ($request->query->get('source') ?? 'wildberries_report_detail');

        $start = new \DateTimeImmutable($ym . '-01 00:00:00', new \DateTimeZone('UTC'));
        $end = $start->modify('first day of next month');

        $em = $this->doctrine->getManager();
        $conn = $em->getConnection();

        $sql = <<<SQL
SELECT
  to_char(il.started_at AT TIME ZONE 'UTC', 'IYYY') AS iso_year,
  to_char(il.started_at AT TIME ZONE 'UTC', 'IW') AS iso_week,
  MIN(il.started_at) AS started_min,
  MAX(il.finished_at) AS finished_max,
  COUNT(*) AS imports_count,
  SUM(il.created_count) AS created_sum,
  SUM(il.skipped_duplicates) AS skipped_sum,
  SUM(il.errors_count) AS errors_sum
FROM import_log il
WHERE il.company_id = :companyId
  AND il.source = :source
  AND il.started_at >= :start
  AND il.started_at < :end
GROUP BY to_char(il.started_at AT TIME ZONE 'UTC', 'IYYY'),
         to_char(il.started_at AT TIME ZONE 'UTC', 'IW')
ORDER BY iso_year DESC, iso_week DESC
SQL;

        $weeks = $conn->executeQuery(
            $sql,
            [
                'companyId' => $company->getId(),
                'source' => $source,
                'start' => $start,
                'end' => $end,
            ],
            [
                'companyId' => \PDO::PARAM_STR,
                'source' => \PDO::PARAM_STR,
                'start' => Types::DATETIME_IMMUTABLE,
                'end' => Types::DATETIME_IMMUTABLE,
            ]
        )->fetchAllAssociative();

        return $this->render('wb/report_detail/month_show.html.twig', [
            'company' => $company,
            'ym' => $ym,
            'source' => $source,
            'weeks' => $weeks,
            'period' => ['from' => $start, 'to' => $end->modify('-1 second')],
        ]);
    }

    /**
     * НЕДЕЛЯ → список импортов (ImportLog) за ISO-неделю.
     * GET /wb/report_detail/{ym}/week/{isoYear}-{isoWeek}
     */
    #[Route(
        path: '/{ym}/week/{key}',
        name: 'week_show',
        methods: ['GET'],
        requirements: ['ym' => '\\d{4}-\\d{2}', 'key' => '\\d{4}-\\d{2}']
    )]
    public function weekShow(Request $request, string $ym, string $key): Response
    {
        $company = $this->companyContext->getActiveCompany();
        $source = (string) ($request->query->get('source') ?? 'wildberries_report_detail');

        [$isoYear, $isoWeek] = explode('-', $key);
        $isoYear = (int) $isoYear;
        $isoWeek = (int) $isoWeek;

        $weekStart = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setISODate($isoYear, $isoWeek, 1)->setTime(0, 0);
        $weekEnd = $weekStart->modify('+1 week');

        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(ImportLog::class);

        $qb = $repo->createQueryBuilder('il')
            ->where('il.company = :company')
            ->andWhere('il.source = :source')
            ->andWhere('il.startedAt >= :start')
            ->andWhere('il.startedAt < :end')
            ->setParameter('company', $company)
            ->setParameter('source', $source)
            ->setParameter('start', $weekStart)
            ->setParameter('end', $weekEnd)
            ->orderBy('il.startedAt', 'DESC');

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(10, min(100, (int) $request->query->get('per_page', 20)));

        $conn = $em->getConnection();
        $total = (int) $conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('import_log', 'il')
            ->where('il.company_id = :companyId')
            ->andWhere('il.source = :source')
            ->andWhere('il.started_at >= :start')
            ->andWhere('il.started_at < :end')
            ->setParameter('companyId', $company->getId(), Types::GUID)
            ->setParameter('source', $source)
            ->setParameter('start', $weekStart, Types::DATETIME_IMMUTABLE)
            ->setParameter('end', $weekEnd, Types::DATETIME_IMMUTABLE)
            ->fetchOne();

        $rows = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return $this->render('wb/report_detail/week_show.html.twig', [
            'company' => $company,
            'ym' => $ym,
            'iso_year' => $isoYear,
            'iso_week' => $isoWeek,
            'source' => $source,
            'week_from' => $weekStart,
            'week_to' => $weekEnd->modify('-1 second'),
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * ИМПОРТ (GUID) → строки детализации WB с фильтрами и пагинацией.
     * GET /wb/report_detail/import/{importId}
     */
    #[Route(path: '/import/{importId}', name: 'import_show', methods: ['GET'])]
    public function importShow(Request $request, string $importId): Response
    {
        $company = $this->companyContext->getActiveCompany();

        $em = $this->doctrine->getManager();
        $repoLog = $em->getRepository(ImportLog::class);
        $repoDet = $em->getRepository(WildberriesReportDetail::class);

        /** @var ImportLog|null $log */
        $log = $repoLog->find($importId);
        if (!$log || $log->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Import not found');
        }

        $nmId = $request->query->get('nmId');
        $brand = $request->query->get('brand');
        $subject = $request->query->get('subject');
        $siteCountry = $request->query->get('siteCountry');
        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(20, min(200, (int) $request->query->get('per_page', 50)));

        $qb = $repoDet->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.importId = :importId')
            ->setParameter('company', $company)
            ->setParameter('importId', $importId);

        if ($nmId) {
            $qb->andWhere('d.nmId = :nmId')->setParameter('nmId', (int) $nmId);
        }
        if ($brand) {
            $qb->andWhere('LOWER(d.brandName) LIKE :brand')->setParameter('brand', '%' . mb_strtolower($brand) . '%');
        }
        if ($subject) {
            $qb->andWhere('LOWER(d.subjectName) LIKE :subject')->setParameter('subject', '%' . mb_strtolower($subject) . '%');
        }
        if ($siteCountry) {
            $qb->andWhere('d.siteCountry = :sc')->setParameter('sc', $siteCountry);
        }
        if ($dateFrom) {
            $qb->andWhere('d.saleDt >= :df')->setParameter('df', new \DateTimeImmutable($dateFrom . ' 00:00:00'));
        }
        if ($dateTo) {
            $qb->andWhere('d.saleDt <= :dt')->setParameter('dt', new \DateTimeImmutable($dateTo . ' 23:59:59'));
        }

        $qb->orderBy('d.saleDt', 'ASC')->addOrderBy('d.rrdId', 'ASC');

        $qbCount = clone $qb;
        $qbCount->select('COUNT(d.id)');
        $total = (int) $qbCount->getQuery()->getSingleScalarResult();

        $rows = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $siteCountryQuery = $repoDet->createQueryBuilder('d')
            ->select('DISTINCT d.siteCountry AS siteCountry')
            ->where('d.company = :company')
            ->andWhere('d.importId = :importId')
            ->setParameter('company', $company)
            ->setParameter('importId', $importId)
            ->orderBy('d.siteCountry', 'ASC');

        $siteCountries = array_values(array_filter(array_map(
            static fn (array $row) => $row['siteCountry'] ?? null,
            $siteCountryQuery->getQuery()->getArrayResult()
        )));

        return $this->render('wb/report_detail/import_show.html.twig', [
            'company' => $company,
            'import' => $log,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'filters' => [
                'nmId' => $nmId,
                'brand' => $brand,
                'subject' => $subject,
                'siteCountry' => $siteCountry,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ],
            'site_countries' => $siteCountries,
        ]);
    }

    /**
     * ДЕТАЛЬ СТРОКИ (JSON для offcanvas).
     * GET /wb/report_detail/row/{id}
     */
    #[Route(path: '/row/{id}', name: 'row_show', methods: ['GET'])]
    public function rowShow(string $id): JsonResponse
    {
        $company = $this->companyContext->getActiveCompany();

        $em = $this->doctrine->getManager();
        /** @var WildberriesReportDetail|null $row */
        $row = $em->getRepository(WildberriesReportDetail::class)->find($id);
        if (!$row || $row->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Row not found');
        }

        $payload = [
            'id' => (string) $row->getId(),
            'rrd_id' => $row->getRrdId(),
            'nm_id' => $row->getNmId(),
            'brand_name' => $row->getBrandName(),
            'subject_name' => $row->getSubjectName(),
            'sale_dt' => $row->getSaleDt()?->format('Y-m-d H:i:s'),
            'order_dt' => $row->getOrderDt()?->format('Y-m-d H:i:s'),
            'rr_dt' => $row->getRrDt()?->format('Y-m-d H:i:s'),
            'retail_price_with_disc_rub' => null !== $row->getRetailPriceWithDiscRub() ? (float) $row->getRetailPriceWithDiscRub() : null,
            'ppvz_sales_commission' => null !== $row->getPpvzSalesCommission() ? (float) $row->getPpvzSalesCommission() : null,
            'delivery_rub' => null !== $row->getDeliveryRub() ? (float) $row->getDeliveryRub() : null,
            'storage_fee' => null !== $row->getStorageFee() ? (float) $row->getStorageFee() : null,
            'acquiring_fee' => null !== $row->getAcquiringFee() ? (float) $row->getAcquiringFee() : null,
            'penalty' => null !== $row->getPenalty() ? (float) $row->getPenalty() : null,
            'ppvz_for_pay' => null !== $row->getPpvzForPay() ? (float) $row->getPpvzForPay() : null,
            'site_country' => $row->getSiteCountry(),
            'supplier_oper_name' => $row->getSupplierOperName(),
            'doc_type_name' => $row->getDocTypeName(),
            'raw' => $row->getRaw(),
        ];

        return $this->json($payload);
    }
}
