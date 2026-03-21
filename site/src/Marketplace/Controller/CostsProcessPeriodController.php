<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Application\Processor\OzonCostsRawProcessor;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Обрабатывает затраты за период вызывая process() для каждого raw-документа.
 *
 * В отличие от «Переобработать» (который идёт через pipeline и видит только COST bucket),
 * этот эндпоинт вызывает process() напрямую — он читает все операции из raw-документа
 * включая type=orders (sale_commission, services[]).
 *
 * Использование:
 *   GET /marketplace/costs/admin/process-period?year=2026&month=1          → предпросмотр
 *   GET /marketplace/costs/admin/process-period?year=2026&month=1&run=1    → запустить
 */
#[Route('/marketplace/costs/admin')]
#[IsGranted('ROLE_USER')]
final class CostsProcessPeriodController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService  $companyService,
        private readonly Connection            $connection,
        private readonly OzonCostsRawProcessor $processor,
    ) {
    }

    #[Route('/process-period', name: 'marketplace_costs_process_period', methods: ['GET'])]
    public function processPeriod(Request $request): JsonResponse
    {
        $company     = $this->companyService->getActiveCompany();
        $companyId   = (string) $company->getId();
        $marketplace = $request->query->get('marketplace', 'ozon');
        $year        = (int) $request->query->get('year', date('Y'));
        $month       = (int) $request->query->get('month', date('n'));
        $run         = $request->query->get('run', '0') === '1';

        $periodFrom = sprintf('%d-%02d-01', $year, $month);
        $periodTo   = (new \DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');

        // Находим все raw-документы за период
        $docs = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT id, period_from::text AS period_from, period_to::text AS period_to, records_count
            FROM marketplace_raw_documents
            WHERE company_id    = :companyId
              AND marketplace   = :marketplace
              AND document_type = 'sales_report'
              AND period_from  >= :periodFrom
              AND period_to    <= :periodTo
            ORDER BY period_from
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        if (!$run) {
            return $this->json([
                'action'    => 'preview',
                'period'    => "{$periodFrom} – {$periodTo}",
                'doc_count' => count($docs),
                'docs'      => array_map(static fn (array $d) => [
                    'id'            => $d['id'],
                    'period'        => $d['period_from'] . ' – ' . $d['period_to'],
                    'records_count' => (int) $d['records_count'],
                ], $docs),
                'next_step' => "/marketplace/costs/admin/process-period?marketplace={$marketplace}&year={$year}&month={$month}&run=1",
            ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
        }

        // Запускаем process() для каждого документа
        $results = [];
        foreach ($docs as $doc) {
            $rawDocId = $doc['id'];

            try {
                $this->processor->process($companyId, $rawDocId);
                $results[] = [
                    'id'     => $rawDocId,
                    'period' => $doc['period_from'] . ' – ' . $doc['period_to'],
                    'status' => 'ok',
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'id'     => $rawDocId,
                    'period' => $doc['period_from'] . ' – ' . $doc['period_to'],
                    'status' => 'error',
                    'error'  => $e->getMessage(),
                ];
            }
        }

        return $this->json([
            'action'  => 'processed',
            'period'  => "{$periodFrom} – {$periodTo}",
            'results' => $results,
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }
}
