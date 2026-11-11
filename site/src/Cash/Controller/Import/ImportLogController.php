<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Repository\Import\ImportLogRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/import-logs')]
final class ImportLogController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ImportLogRepository $repo,
    ) {
    }

    #[Route('', name: 'import_logs_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
        $source = $request->query->get('source');
        $query = $request->query->all();
        $rawFrom = $query['date_from'] ?? null;
        $rawTo = $query['date_to'] ?? null;
        $dateFrom = is_string($rawFrom) ? $rawFrom : null;
        $dateTo = is_string($rawTo) ? $rawTo : null;
        $validDates = (null === $rawFrom || is_string($rawFrom)) && (null === $rawTo || is_string($rawTo));
        foreach ([$dateFrom, $dateTo] as $value) {
            if (null === $value || '' === $value) {
                continue;
            }
            if (1 !== preg_match('/^(?!0000)\d{4}-\d{2}-\d{2}$/D', $value)) {
                $validDates = false;
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $validDates = $validDates && false !== $date && $date->format('Y-m-d') === $value;
        }
        $validDates = $validDates && (!$dateFrom || !$dateTo || $dateFrom <= $dateTo);
        if (!$validDates) {
            $this->addFlash('danger', 'Укажите существующие даты в формате ГГГГ-ММ-ДД; начало периода не может быть позже окончания.');
            $pager = new \Pagerfanta\Pagerfanta(new \Pagerfanta\Adapter\ArrayAdapter([]));
        } else {
            $pager = $this->repo->paginateByCompany($company, $page, $limit, ['source' => $source, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]);
        }
        $items = iterator_to_array($pager->getCurrentPageResults());
        $total = $pager->getNbResults();

        return $this->render('import_log/index.html.twig', ['pager' => $pager, 'items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit, 'source' => $source, 'date_from' => $dateFrom, 'date_to' => $dateTo], new Response(status: $validDates ? 200 : 422));
    }
}
