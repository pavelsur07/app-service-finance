<?php

namespace App\Controller\Finance;

use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportParams;
use App\Report\Cashflow\CashflowReportRequestMapper;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ReportCashflowController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private CashflowReportRequestMapper $mapper,
        private CashflowReportBuilder $builder,
    ) {
    }

    #[Route('/finance/reports/cashflow/export.json', name: 'report_cashflow_export_json', methods: ['GET'])]
    public function exportJson(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        /** @var CashflowReportParams $params */
        $params = $this->mapper->fromRequest($request, $company);
        $payload = $this->builder->build($params);

        $dateFrom = $payload['date_from']->format('Y-m-d');
        $dateTo = $payload['date_to']->format('Y-m-d');

        $response = new JsonResponse([
            'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'filters' => [
                'group' => $payload['group'],
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'company' => $payload['company']->getId(),
            'group' => $payload['group'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'periods' => array_map(static fn (array $period): array => [
                'start' => $period['start']->format('Y-m-d'),
                'end' => $period['end']->format('Y-m-d'),
                'label' => $period['label'],
            ], $payload['periods']),
            'categories' => array_map(static fn ($category): array => [
                'id' => $category->getId(),
                'name' => $category->getName(),
            ], $payload['categories']),
            'openings' => $payload['openings'],
            'closings' => $payload['closings'],
            'tree' => $payload['tree'],
            'categoryTree' => $payload['categoryTree'],
            'categoryTotals' => array_map(
                static fn (array $row): array => [
                    'totals' => $row['totals'],
                ],
                $payload['categoryTotals']
            ),
        ]);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set(
            'Content-Disposition',
            \sprintf('attachment; filename="cashflow-report-%s_%s.json"', $dateFrom, $dateTo),
        );

        return $response;
    }

    #[Route('/finance/reports/cashflow', name: 'report_cashflow_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        /** @var CashflowReportParams $params */
        $params = $this->mapper->fromRequest($request, $company);
        $payload = $this->builder->build($params);

        return $this->render('finance/report/cashflow.html.twig', $payload);
    }
}
