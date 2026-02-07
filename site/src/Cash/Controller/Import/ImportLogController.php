<?php

namespace App\Cash\Controller\Import;

use App\Cash\Repository\Import\ImportLogRepository;
use App\Shared\Service\ActiveCompanyService;
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

        $pager = $repo->paginateByCompany($company, $page, $limit, [
            'source' => $source,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
        $items = iterator_to_array($pager->getCurrentPageResults());
        $total = $pager->getNbResults();

        return $this->render('import_log/index.html.twig', [
            'pager' => $pager,
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
