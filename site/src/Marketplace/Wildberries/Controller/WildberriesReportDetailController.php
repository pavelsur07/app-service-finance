<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Marketplace\Wildberries\Entity\WildberriesReportDetail;
use App\Service\ActiveCompanyService;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

    #[Route(path: '', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->companyContext->getActiveCompany();

        $em = $this->doctrine->getManager();
        $repoDet = $em->getRepository(WildberriesReportDetail::class);

        $nmId = $request->query->get('nmId');
        $brand = $request->query->get('brand');
        $subject = $request->query->get('subject');
        $siteCountry = $request->query->get('siteCountry');
        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');
        $importId = $request->query->get('importId');

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(20, min(200, (int) $request->query->get('per_page', 50)));

        $qb = $repoDet->createQueryBuilder('d')
            ->where('d.company = :company')
            ->setParameter('company', $company);

        if ($importId) {
            $qb->andWhere('d.importId = :importId')->setParameter('importId', $importId);
        }
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
            $qb->andWhere('d.siteCountry = :siteCountry')->setParameter('siteCountry', $siteCountry);
        }
        if ($dateFrom) {
            $qb->andWhere('d.saleDt >= :dateFrom')->setParameter('dateFrom', new \DateTimeImmutable($dateFrom.' 00:00:00'));
        }
        if ($dateTo) {
            $qb->andWhere('d.saleDt <= :dateTo')->setParameter('dateTo', new \DateTimeImmutable($dateTo.' 23:59:59'));
        }

        $qb->orderBy('d.saleDt', 'DESC');

        // Список стран площадки оставляем как есть (отдельный запрос)
        $siteCountryQuery = $repoDet->createQueryBuilder('d')
            ->select('DISTINCT d.siteCountry AS siteCountry')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.siteCountry', 'ASC');

        $siteCountries = array_values(array_filter(array_map(
            static fn (array $row) => $row['siteCountry'] ?? null,
            $siteCountryQuery->getQuery()->getArrayResult()
        )));

        // Pagerfanta для пагинации операций
        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        $rows = iterator_to_array($pager->getCurrentPageResults());
        $total = $pager->getNbResults();

        return $this->render('wb/report_detail/index.html.twig', [
            'company' => $company,
            'rows' => $rows,
            'total' => $total,
            'pager' => $pager,
            'per_page' => $pager->getMaxPerPage(),
            'filters' => [
                'nmId' => $nmId,
                'brand' => $brand,
                'subject' => $subject,
                'siteCountry' => $siteCountry,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'importId' => $importId,
            ],
            'site_countries' => $siteCountries,
        ]);
    }
}
