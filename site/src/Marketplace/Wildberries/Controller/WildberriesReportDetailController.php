<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Entity\Company;
use App\Entity\ImportLog;                  // ← проверьте namespace/имя сущности журнала импортов
use App\Marketplace\Wildberries\Entity\WildberriesReportDetail; // ← проверьте namespace/имя сущности строк детализации
use App\Service\ActiveCompanyService;        // ← ваш сервис активной компании
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/wb/finance/reports/detail', name: 'wb_report_detail_')]
final class WildberriesReportDetailController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ActiveCompanyService $companyContext,
    ) {
    }

    /**
     * ЖУРНАЛ ИМПОРТОВ, сгруппированных по batch_id.
     * GET /wb/finance/reports/detail/imports.
     */
    #[Route(path: '/imports', name: 'imports_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $this->companyContext->getActiveCompany();

        $em = $this->doctrine->getManager();
        $qb = $em->getRepository(ImportLog::class)->createQueryBuilder('ij');

        // В журнале показываем только наш источник
        $source = 'wb_report_detail';

        // группируем по batchId: считаем сумму строк/ошибок и т.п.
        // ВАЖНО: проверьте точные имена полей в вашей сущности ImportJournal!
        $qb
            ->select('ij.batchId AS batchId')
            ->addSelect('MIN(ij.periodFrom) AS periodFrom')
            ->addSelect('MAX(ij.periodTo) AS periodTo')
            ->addSelect('COUNT(ij.id) AS importsCount')
            ->addSelect('SUM(COALESCE(ij.rowsTotal,0)) AS rowsTotalSum')
            ->addSelect('SUM(COALESCE(ij.errorsCount,0)) AS errorsSum')
            ->addSelect('MIN(ij.startedAt) AS startedAt')
            ->addSelect('MAX(ij.finishedAt) AS finishedAt')
            ->where('ij.company = :company')
            ->andWhere('ij.source = :source')
            ->groupBy('ij.batchId')
            ->orderBy('periodFrom', 'DESC')
            ->setParameter('company', $company)
            ->setParameter('source', $source);

        // простая пагинация по батчам
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(10, min(100, (int) $request->query->get('per_page', 10)));
        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        $batches = $qb->getQuery()->getArrayResult();

        // Для каждой группы можно досчитать "Σ к выплате" — суммируя по строкам импорта,
        // но это дороже; в примере отобразим только сумму строк. KPI по "к выплате"
        // считаем на экране группы (там уже знаем все importId).

        return $this->render('wb/report_detail/imports.html.twig', [
            'company' => $company,
            'batches' => $batches,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * ГРУППА (BATCH): сводка по всем под-импортам (неделям) внутри batch_id.
     * GET /wb/finance/reports/detail/imports/batch/{batchId}.
     */
    #[Route(path: '/imports/batch/{batchId}', name: 'batch_show', methods: ['GET'])]
    public function showBatch(Request $request, string $batchId): Response
    {
        /** @var Company $company */
        $company = $this->companyContext->getCurrentCompanyOrFail();

        $em = $this->doctrine->getManager();
        $repoIJ = $em->getRepository(ImportJournal::class);

        // Собираем под-импорты (недели) внутри batchId
        $importsQb = $repoIJ->createQueryBuilder('ij')
            ->where('ij.company = :company')
            ->andWhere('ij.source = :source')
            ->andWhere('ij.batchId = :batchId')
            ->setParameter('company', $company)
            ->setParameter('source', 'wb_report_detail')
            ->setParameter('batchId', $batchId)
            ->orderBy('ij.periodFrom', 'ASC');

        $imports = $importsQb->getQuery()->getResult();

        // KPI по группе (Σ по всем под-импортам) считаем через суммирование строк детализации.
        // Для этого достанем все importId из журнала, и просуммируем по WildberriesReportDetail.ppvzForPay и др.
        $importIds = array_map(static fn (ImportJournal $j) => $j->getId(), $imports);
        $kpi = [
            'rowsTotal' => 0,
            'retailWithDisc' => 0.0,
            'commission' => 0.0,
            'delivery' => 0.0,
            'storage' => 0.0,
            'acquiring' => 0.0,
            'penalty' => 0.0,
            'forPay' => 0.0,
        ];

        if (!empty($importIds)) {
            $repoDetail = $em->getRepository(WildberriesReportDetail::class);
            $qb = $repoDetail->createQueryBuilder('d')
                ->select('COUNT(d.id) AS cnt')
                ->addSelect('COALESCE(SUM(d.retailPriceWithDiscRub),0) AS retailWithDisc')
                ->addSelect('COALESCE(SUM(d.ppvzSalesCommission),0) AS commission')
                ->addSelect('COALESCE(SUM(d.deliveryRub),0) AS delivery')
                ->addSelect('COALESCE(SUM(d.storageFee),0) AS storage')
                ->addSelect('COALESCE(SUM(d.acquiringFee),0) AS acquiring')
                ->addSelect('COALESCE(SUM(d.penalty),0) AS penalty')
                ->addSelect('COALESCE(SUM(d.ppvzForPay),0) AS forPay')
                ->where('d.company = :company')
                ->andWhere('d.importId IN (:ids)') // ← убедитесь, что в строках есть поле importId (uuid сеанса)
                ->setParameter('company', $company)
                ->setParameter('ids', $importIds);

            $row = $qb->getQuery()->getSingleResult();
            $kpi['rowsTotal'] = (int) $row['cnt'];
            $kpi['retailWithDisc'] = (float) $row['retailWithDisc'];
            $kpi['commission'] = (float) $row['commission'];
            $kpi['delivery'] = (float) $row['delivery'];
            $kpi['storage'] = (float) $row['storage'];
            $kpi['acquiring'] = (float) $row['acquiring'];
            $kpi['penalty'] = (float) $row['penalty'];
            $kpi['forPay'] = (float) $row['forPay'];
        }

        return $this->render('wb/report_detail/batch_show.html.twig', [
            'company' => $company,
            'batchId' => $batchId,
            'imports' => $imports,
            'kpi' => $kpi,
        ]);
    }

    /**
     * ИМПОРТ (НЕДЕЛЯ): список строк с фильтрами и пагинацией.
     * GET /wb/finance/reports/detail/imports/{importId}.
     */
    #[Route(path: '/imports/{importId}', name: 'import_show', methods: ['GET'])]
    public function showImport(Request $request, string $importId): Response
    {
        /** @var Company $company */
        $company = $this->companyContext->getCurrentCompanyOrFail();

        $em = $this->doctrine->getManager();
        $repoIJ = $em->getRepository(ImportJournal::class);
        $repoDetail = $em->getRepository(WildberriesReportDetail::class);

        /** @var ImportJournal|null $import */
        $import = $repoIJ->find($importId);
        if (!$import || $import->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Import not found');
        }

        // Фильтры
        $nmId = $request->query->get('nmId');
        $brand = $request->query->get('brand');
        $subject = $request->query->get('subject');
        $siteCountry = $request->query->get('siteCountry');
        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(20, min(200, (int) $request->query->get('per_page', 50)));

        $qb = $repoDetail->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.importId = :importId')
            ->setParameter('company', $company)
            ->setParameter('importId', $importId);

        if ($nmId) {
            $qb->andWhere('d.nmId = :nmId')->setParameter('nmId', (int) $nmId);
        }
        if ($brand) {
            $qb->andWhere('LOWER(d.brandName) LIKE :brand')->setParameter('brand', '%'.mb_strtolower($brand).'%');
        }
        if ($subject) {
            $qb->andWhere('LOWER(d.subjectName) LIKE :subject')->setParameter('subject', '%'.mb_strtolower($subject).'%');
        }
        if ($siteCountry) {
            $qb->andWhere('d.siteCountry = :sc')->setParameter('sc', $siteCountry);
        }
        if ($dateFrom) {
            $qb->andWhere('d.saleDt >= :df')->setParameter('df', new \DateTimeImmutable($dateFrom.' 00:00:00'));
        }
        if ($dateTo) {
            $qb->andWhere('d.saleDt <= :dt')->setParameter('dt', new \DateTimeImmutable($dateTo.' 23:59:59'));
        }

        $qb->orderBy('d.saleDt', 'ASC')->addOrderBy('d.rrdId', 'ASC');

        // Пагинация
        $qbCount = clone $qb;
        $qbCount->select('COUNT(d.id)');
        $total = (int) $qbCount->getQuery()->getSingleScalarResult();

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        $rows = $qb->getQuery()->getResult();

        return $this->render('wb/report_detail/import_show.html.twig', [
            'company' => $company,
            'import' => $import,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,

            // пробрасываем активные фильтры обратно в шаблон
            'filters' => [
                'nmId' => $nmId,
                'brand' => $brand,
                'subject' => $subject,
                'siteCountry' => $siteCountry,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ],
        ]);
    }

    /**
     * ДЕТАЛИ СТРОКИ ДЛЯ OFFCANVAS (JSON + RAW).
     * GET /wb/finance/reports/detail/row/{id}.
     */
    #[Route(path: '/row/{id}', name: 'row_show', methods: ['GET'])]
    public function showRow(string $id): JsonResponse
    {
        /** @var Company $company */
        $company = $this->companyContext->getCurrentCompanyOrFail();

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
            'barcode' => $row->getBarcode(),
            'brand' => $row->getBrandName(),
            'subject' => $row->getSubjectName(),
            'sale_dt' => $row->getSaleDt()?->format('Y-m-d H:i:s'),
            'order_dt' => $row->getOrderDt()?->format('Y-m-d H:i:s'),
            'rr_dt' => $row->getRrDt()?->format('Y-m-d H:i:s'),
            'retail_with_disc' => (float) $row->getRetailPriceWithDiscRub(),
            'commission' => (float) $row->getPpvzSalesCommission(),
            'delivery' => (float) $row->getDeliveryRub(),
            'storage' => (float) $row->getStorageFee(),
            'acquiring' => (float) $row->getAcquiringFee(),
            'penalty' => (float) $row->getPenalty(),
            'for_pay' => (float) $row->getPpvzForPay(),
            'site_country' => $row->getSiteCountry(),
            'supplier_oper' => $row->getSupplierOperName(),
            'doc_type' => $row->getDocTypeName(),
            'raw' => $row->getRaw(), // json array
        ];

        return $this->json($payload);
    }
}
