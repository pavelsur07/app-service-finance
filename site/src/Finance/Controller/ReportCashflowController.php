<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Finance\Infrastructure\Normalizer\CashflowReportJsonFormatter;
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
        private CashflowReportJsonFormatter $jsonFormatter,
    ) {
    }

    #[Route('/finance/reports/cashflow/export.json', name: 'report_cashflow_export_json', methods: ['GET'])]
    public function exportJson(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        /** @var CashflowReportParams $params */
        $params = $this->mapper->fromRequest($request, $company);
        $payload = $this->builder->build($params);

        $formatted = $this->jsonFormatter->format($payload, [
            'include_exported_at' => true,
            'dataset' => 'cashflow',
            'include_filters' => true,
        ]);
        $dateFrom = $formatted['date_from'];
        $dateTo = $formatted['date_to'];

        $response = new JsonResponse($formatted);
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
