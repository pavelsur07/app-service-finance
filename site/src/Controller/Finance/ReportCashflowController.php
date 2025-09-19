<?php

namespace App\Controller\Finance;

use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportRequestMapper;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/reports/cashflow')]
class ReportCashflowController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private CashflowReportBuilder $builder,
        private CashflowReportRequestMapper $mapper,
    ) {
    }

    #[Route('', name: 'report_cashflow_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $params = $this->mapper->fromRequest($request, $company);
        $payload = $this->builder->build($params);

        return $this->render('finance/report/cashflow.html.twig', $payload);
    }
}
