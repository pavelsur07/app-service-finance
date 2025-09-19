<?php

namespace App\Controller\Finance;

use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportParams;
use App\Report\Cashflow\CashflowReportRequestMapper;
use App\Repository\CompanyRepository;
use App\Service\ActiveCompanyService;
use App\Service\ReportApiKeyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[Route('/finance/reports/cashflow')]
class ReportCashflowController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private ReportApiKeyManager $keys,
        private CompanyRepository $companyRepo,
        private CashflowReportRequestMapper $mapper,
        private CashflowReportBuilder $builder,
    ) {
    }

    #[Route('', name: 'report_cashflow_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        /** @var CashflowReportParams $params */
        $params = $this->mapper->fromRequest($request, $company);
        $payload = $this->builder->build($params);

        return $this->render('finance/report/cashflow.html.twig', $payload);
    }

    #[Route('/api/public/reports/cashflow.json', name: 'api_report_cashflow_json', methods: ['GET'])]
    public function apiJson(Request $r, RateLimiterFactory $reportsApiLimiter): Response
    {
        $token = (string) $r->query->get('token', '');
        $limiter = $reportsApiLimiter->create($token ?: ($r->getClientIp() ?? 'anon'));
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }
        if ($token === '') {
            return new JsonResponse(['error' => 'token_required'], 401);
        }

        $company = $this->keys->findCompanyByRawKey($token, $this->companyRepo);
        if (!$company) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        /** @var CashflowReportParams $params */
        $params = $this->mapper->fromRequest($r, $company);
        $payload = $this->builder->build($params);

        return $this->json([
            'company' => $payload['company']->getId(),
            'group' => $payload['group'],
            'date_from' => $payload['date_from']->format('Y-m-d'),
            'date_to' => $payload['date_to']->format('Y-m-d'),
            'periods' => array_map(
                fn ($p) => [
                    'start' => $p['start']->format('Y-m-d'),
                    'end' => $p['end']->format('Y-m-d'),
                    'label' => $p['label'],
                ],
                $payload['periods']
            ),
            'categories' => array_map(
                fn ($c) => ['id' => $c->getId(), 'name' => $c->getName()],
                $payload['categories']
            ),
            'categoryTotals' => $payload['categoryTotals'],
            'openings' => $payload['openings'],
            'closings' => $payload['closings'],
        ]);
    }

    #[Route('/api/public/reports/cashflow.csv', name: 'api_report_cashflow_csv', methods: ['GET'])]
    public function apiCsv(Request $r, RateLimiterFactory $reportsApiLimiter): Response
    {
        $token = (string) $r->query->get('token', '');
        $limiter = $reportsApiLimiter->create($token ?: ($r->getClientIp() ?? 'anon'));
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }
        if ($token === '') {
            return new JsonResponse(['error' => 'token_required'], 401);
        }

        $company = $this->keys->findCompanyByRawKey($token, $this->companyRepo);
        if (!$company) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        /** @var CashflowReportParams $params */
        $params = $this->mapper->fromRequest($r, $company);
        $payload = $this->builder->build($params);

        $periods = $payload['periods'];
        $categoryTotals = $payload['categoryTotals'];
        $openings = $payload['openings'];
        $closings = $payload['closings'];

        $resp = new StreamedResponse(function () use ($periods, $categoryTotals, $openings, $closings) {
            $out = \fopen('php://output', 'w');
            \fputcsv($out, ['Период', 'КатегорияID', 'Валюта', 'Сальдо нач.', 'Нетто', 'Сальдо кон.']);
            foreach ($periods as $i => $p) {
                $label = $p['label'];
                foreach ($categoryTotals as $catId => $catRow) {
                    if (!isset($catRow['totals'])) {
                        continue;
                    }

                    foreach ($catRow['totals'] as $currency => $vals) {
                        $opening = $openings[$currency][$i] ?? 0.0;
                        $net = $vals[$i] ?? 0.0;
                        $closing = $closings[$currency][$i] ?? 0.0;
                        \fputcsv($out, [$label, $catId, $currency, $opening, $net, $closing]);
                    }
                }
            }
            \fclose($out);
        });
        $resp->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $resp->headers->set('Cache-Control', 'max-age=60');

        return $resp;
    }
}
