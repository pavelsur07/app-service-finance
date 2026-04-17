<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Application\Processor\OzonCostsRawProcessor;
use App\Marketplace\Application\Processor\OzonReturnsRawProcessor;
use App\Marketplace\Application\Processor\OzonSalesRawProcessor;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отладочный эндпоинт для чистки orphan-записей и переобработки периода.
 *
 * Удаляет записи из marketplace_sales / marketplace_returns / marketplace_costs,
 * у которых raw_document_id IS NULL (созданы старым ProcessOzonSalesAction или
 * другим legacy-способом), и переобрабатывает все sales_report raw-документы
 * за указанный период через OzonSalesRawProcessor / OzonReturnsRawProcessor /
 * OzonCostsRawProcessor.
 *
 * Использование:
 *   POST /api/marketplace-analytics/debug/reprocess-period
 *        ?marketplace=ozon&from=2026-02-01&to=2026-02-28
 *        [&confirm=1]
 *
 * Без confirm=1 — preview: показывает сколько orphan-записей будет удалено
 * и какие raw-документы будут переобработаны, без внесения изменений.
 */
#[Route(
    path: '/api/marketplace-analytics/debug/reprocess-period',
    name: 'api_marketplace_analytics_debug_reprocess_period',
    methods: ['POST'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugReprocessPeriodController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
        private readonly OzonSalesRawProcessor $salesProcessor,
        private readonly OzonReturnsRawProcessor $returnsProcessor,
        private readonly OzonCostsRawProcessor $costsProcessor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $marketplaceStr = (string) $request->query->get('marketplace', '');
        $fromStr        = (string) $request->query->get('from', '');
        $toStr          = (string) $request->query->get('to', '');
        $confirm        = (string) $request->query->get('confirm', '0') === '1';

        if ($marketplaceStr === '' || $fromStr === '' || $toStr === '') {
            return $this->json(['error' => 'marketplace, from, to are required'], 422);
        }

        $marketplace = MarketplaceType::tryFrom($marketplaceStr);
        if ($marketplace === null) {
            return $this->json(['error' => 'Unknown marketplace: ' . $marketplaceStr], 422);
        }

        if ($marketplace !== MarketplaceType::OZON) {
            return $this->json(['error' => 'Only ozon is supported at the moment'], 422);
        }

        try {
            $from = new \DateTimeImmutable($fromStr);
            $to   = new \DateTimeImmutable($toStr);
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid date format (Y-m-d)'], 422);
        }

        if ($from > $to) {
            return $this->json(['error' => 'from must be <= to'], 422);
        }

        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $orphans = $this->countOrphans($companyId, $marketplace, $from, $to);
        $rawDocs = $this->findRawDocuments($companyId, $marketplace, $from, $to);

        if (!$confirm) {
            return new JsonResponse([
                'preview'     => true,
                'marketplace' => $marketplace->value,
                'companyId'   => $companyId,
                'period'      => [
                    'from' => $from->format('Y-m-d'),
                    'to'   => $to->format('Y-m-d'),
                ],
                'orphansToDelete'         => $orphans,
                'rawDocumentsToReprocess' => [
                    'count'     => count($rawDocs),
                    'documents' => $rawDocs,
                ],
                'hint' => 'Add &confirm=1 to execute',
            ]);
        }

        $totalsBefore = $this->countTotals($companyId, $marketplace, $from, $to);

        $deleted          = $this->deleteOrphans($companyId, $marketplace, $from, $to);
        $reprocessResults = $this->reprocessDocuments($companyId, $rawDocs);

        $totalsAfter = $this->countTotals($companyId, $marketplace, $from, $to);

        return new JsonResponse([
            'preview'     => false,
            'marketplace' => $marketplace->value,
            'companyId'   => $companyId,
            'period'      => [
                'from' => $from->format('Y-m-d'),
                'to'   => $to->format('Y-m-d'),
            ],
            'deletedOrphans'          => $deleted,
            'rawDocumentsReprocessed' => [
                'count'   => count($reprocessResults),
                'results' => $reprocessResults,
            ],
            'totalsBefore' => $totalsBefore,
            'totalsAfter'  => $totalsAfter,
            'netChange'    => [
                'sales'   => $totalsAfter['sales']   - $totalsBefore['sales'],
                'returns' => $totalsAfter['returns'] - $totalsBefore['returns'],
                'costs'   => $totalsAfter['costs']   - $totalsBefore['costs'],
            ],
        ]);
    }

    /**
     * @return array{sales: int, returns: int, costs: int}
     */
    private function countOrphans(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace->value,
            'periodFrom'  => $from->format('Y-m-d'),
            'periodTo'    => $to->format('Y-m-d'),
        ];

        $sales = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*) FROM marketplace_sales
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND sale_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
            SQL,
            $params,
        );

        $returns = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*) FROM marketplace_returns
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND return_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
            SQL,
            $params,
        );

        $costs = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*) FROM marketplace_costs
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND cost_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
            SQL,
            $params,
        );

        return ['sales' => $sales, 'returns' => $returns, 'costs' => $costs];
    }

    /**
     * @return array{sales: int, returns: int, costs: int}
     */
    private function countTotals(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace->value,
            'periodFrom'  => $from->format('Y-m-d'),
            'periodTo'    => $to->format('Y-m-d'),
        ];

        $sales = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*) FROM marketplace_sales
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND sale_date BETWEEN :periodFrom AND :periodTo
            SQL,
            $params,
        );

        $returns = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*) FROM marketplace_returns
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND return_date BETWEEN :periodFrom AND :periodTo
            SQL,
            $params,
        );

        $costs = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*) FROM marketplace_costs
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND cost_date BETWEEN :periodFrom AND :periodTo
            SQL,
            $params,
        );

        return ['sales' => $sales, 'returns' => $returns, 'costs' => $costs];
    }

    /**
     * Находит завершённые raw-документы типа sales_report, период которых
     * пересекается с запрошенным диапазоном. Для Ozon именно sales_report
     * является источником данных для всех трёх процессоров (sales/returns/costs).
     *
     * @return list<array{id: string, periodFrom: string, periodTo: string, syncedAt: string, recordsCount: int, processingStatus: string}>
     */
    private function findRawDocuments(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                mrd.id,
                mrd.period_from::text       AS period_from,
                mrd.period_to::text         AS period_to,
                mrd.synced_at::text         AS synced_at,
                mrd.records_count,
                mrd.processing_status
            FROM marketplace_raw_documents mrd
            WHERE mrd.company_id        = :companyId
              AND mrd.marketplace       = :marketplace
              AND mrd.document_type     = 'sales_report'
              AND mrd.processing_status = 'completed'
              AND mrd.period_from      <= :periodTo
              AND mrd.period_to        >= :periodFrom
            ORDER BY mrd.period_from, mrd.synced_at
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace->value,
                'periodFrom'  => $from->format('Y-m-d'),
                'periodTo'    => $to->format('Y-m-d'),
            ],
        );

        return array_map(
            static fn (array $r): array => [
                'id'               => (string) $r['id'],
                'periodFrom'       => (string) $r['period_from'],
                'periodTo'         => (string) $r['period_to'],
                'syncedAt'         => (string) $r['synced_at'],
                'recordsCount'     => (int) $r['records_count'],
                'processingStatus' => (string) $r['processing_status'],
            ],
            $rows,
        );
    }

    /**
     * @return array{sales: int, returns: int, costs: int}
     */
    private function deleteOrphans(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace->value,
            'periodFrom'  => $from->format('Y-m-d'),
            'periodTo'    => $to->format('Y-m-d'),
        ];

        // document_id IS NULL — не трогаем уже закрытые в ОПиУ записи.
        $sales = (int) $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM marketplace_sales
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND sale_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
              AND document_id IS NULL
            SQL,
            $params,
        );

        $returns = (int) $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM marketplace_returns
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND return_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
              AND document_id IS NULL
            SQL,
            $params,
        );

        $costs = (int) $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM marketplace_costs
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND cost_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
              AND document_id IS NULL
            SQL,
            $params,
        );

        $this->logger->info('[DebugReprocessPeriod] Orphans deleted', [
            'company_id'  => $companyId,
            'marketplace' => $marketplace->value,
            'period_from' => $from->format('Y-m-d'),
            'period_to'   => $to->format('Y-m-d'),
            'sales'       => $sales,
            'returns'     => $returns,
            'costs'       => $costs,
        ]);

        return ['sales' => $sales, 'returns' => $returns, 'costs' => $costs];
    }

    /**
     * Запускает три процессора (sales/returns/costs) для каждого raw-документа.
     * Ошибка одного процессора не прерывает обработку остальных — результат
     * фиксируется в ответе, чтобы можно было разобраться postmortem.
     *
     * @param list<array{id: string, periodFrom: string, periodTo: string, syncedAt: string, recordsCount: int, processingStatus: string}> $rawDocs
     *
     * @return list<array<string, mixed>>
     */
    private function reprocessDocuments(string $companyId, array $rawDocs): array
    {
        $results = [];

        foreach ($rawDocs as $doc) {
            $result = [
                'id'         => $doc['id'],
                'periodFrom' => $doc['periodFrom'],
                'periodTo'   => $doc['periodTo'],
            ];

            foreach (
                [
                    'sales'   => $this->salesProcessor,
                    'returns' => $this->returnsProcessor,
                    'costs'   => $this->costsProcessor,
                ] as $step => $processor
            ) {
                try {
                    $processor->process($companyId, $doc['id']);
                    $result[$step] = 'ok';
                } catch (\Throwable $e) {
                    $result[$step] = 'error: ' . $e->getMessage();
                    $this->logger->error('[DebugReprocessPeriod] Processor failed', [
                        'step'        => $step,
                        'raw_doc_id'  => $doc['id'],
                        'company_id'  => $companyId,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            $results[] = $result;
        }

        return $results;
    }
}
