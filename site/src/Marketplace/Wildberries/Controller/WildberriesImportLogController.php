<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Marketplace\Wildberries\Entity\WildberriesImportLog;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/wildberries/import-logs', name: 'wildberries_import_logs_')]
final class WildberriesImportLogController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ActiveCompanyService $companyContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->companyContext->getActiveCompany();

        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(WildberriesImportLog::class);

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(10, (int) $request->query->get('limit', 20)));

        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        $qb = $repo->createQueryBuilder('l')
            ->andWhere('l.company = :c')->setParameter('c', $company)
            // Журнал именно по фин. отчётам WB
            ->andWhere('l.source = :source')->setParameter('source', 'wb_report_detail');

        if ($dateFrom) {
            $qb->andWhere('l.startedAt >= :df')
                ->setParameter('df', new \DateTimeImmutable($dateFrom.' 00:00:00'));
        }

        if ($dateTo) {
            $qb->andWhere('l.startedAt <= :dt')
                ->setParameter('dt', new \DateTimeImmutable($dateTo.' 23:59:59'));
        }

        $qb->orderBy('l.startedAt', 'DESC');

        $query = $qb->getQuery();
        $paginator = new Paginator($query);
        $total = count($paginator);

        $query->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $items = iterator_to_array($paginator, false);

        return $this->render('wildberries/import_log/index.html.twig', [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }
}
