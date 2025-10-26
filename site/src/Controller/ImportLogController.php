<?php

namespace App\Controller;

use App\Repository\ImportLogRepository;
use App\Service\ActiveCompanyService;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/import-logs')]
class ImportLogController extends AbstractController
{
    #[Route('', name: 'import_logs_index', methods: ['GET'])]
    public function index(
        Request $request,
        ActiveCompanyService $activeCompanyService,
        ImportLogRepository $repo,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(10, (int) $request->query->get('limit', 20)));

        $source = $request->query->get('source');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        $qb = $repo->createQueryBuilder('l')
            ->andWhere('l.company = :c')->setParameter('c', $company)
            ->orderBy('l.startedAt', 'DESC');

        if ($source) {
            $qb->andWhere('l.source = :s')->setParameter('s', $source);
        }
        if ($dateFrom) {
            $qb->andWhere('l.startedAt >= :df')->setParameter('df', new \DateTimeImmutable($dateFrom.' 00:00:00'));
        }
        if ($dateTo) {
            $qb->andWhere('l.startedAt <= :dt')->setParameter('dt', new \DateTimeImmutable($dateTo.' 23:59:59'));
        }

        $query = $qb->getQuery();
        $paginator = new Paginator($query);
        $total = count($paginator);

        $query->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $items = iterator_to_array($paginator);

        return $this->render('import_log/index.html.twig', [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'source' => $source,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }
}
