<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
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
 * у которых raw_document_id IS NULL (созданы legacy-способом), и переобрабатывает
 * все sales_report raw-документы за указанный период через
 * ProcessMarketplaceRawDocumentAction (та же логика, что и daily pipeline).
 *
 * Использование:
 *   POST /api/marketplace-analytics/debug/reprocess-period
 *        ?marketplace=ozon&from=2026-02-01&to=2026-02-28
 *        [&confirm=1]
 *        [&force=1]  — удаляет orphan-записи даже с document_id (закрытые в ОПиУ)
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
        private readonly ProcessMarketplaceRawDocumentAction $processAction,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $marketplaceStr = (string) $request->query->get('marketplace', '');
        $fromStr        = (string) $request->query->get('from', '');
        $toStr          = (string) $request->query->get('to', '');
        $confirm        = (string) $request->query->get('confirm', '0') === '1';
        $force          = (string) $request->query->get('force', '0') === '1';

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

        $from = $this->parseStrictDate($fromStr);
        $to   = $this->parseStrictDate($toStr);
        if ($from === null || $to === null) {
            return $this->json(['error' => 'Invalid date format (Y-m-d expected, must be a real calendar date)'], 422);
        }

        if ($from > $to) {
            return $this->json(['error' => 'from must be <= to'], 422);
        }

        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $orphans = $this->countOrphans($companyId, $marketplace, $from, $to, $force);
        $rawDocs = $this->findRawDocuments($companyId, $marketplace, $from, $to);

        if (!$confirm) {
            return new JsonResponse([
                'preview'     => true,
                'force'       => $force,
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
                'hint' => $force
                    ? 'FORCE MODE: will delete orphans even with document_id. Add &confirm=1 to execute'
                    : 'Add &confirm=1 to execute. Add &force=1 to include orphans with document_id',
            ]);
        }

        $totalsBefore = $this->countTotals($companyId, $marketplace, $from, $to);

        $deleted          = $this->deleteOrphans($companyId, $marketplace, $from, $to, $force);
        $reprocessResults = $this->reprocessDocuments($companyId, $rawDocs);

        $totalsAfter = $this->countTotals($companyId, $marketplace, $from, $to);

        return new JsonResponse([
            'preview'     => false,
            'force'       => $force,
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
     * Строгий парсинг Y-m-d: `new DateTimeImmutable('2026-02-31')` молча
     * нормализует дату в `2026-03-03`, что для destructive endpoint
     * означает обработку не того периода. Round-trip по формату ловит такие
     * нормализации и отвергает их.
     */
    private function parseStrictDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * @return array{sales: int, returns: int, costs: int}
     */
    private function countOrphans(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool $force = false,
    ): array {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace->value,
            'periodFrom'  => $from->format('Y-m-d'),
            'periodTo'    => $to->format('Y-m-d'),
        ];

        $documentFilter = $force ? '' : 'AND document_id IS NULL';

        $sales = (int) $this->connection->fetchOne(
            <<<SQL
            SELECT COUNT(*) FROM marketplace_sales
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND sale_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
              {$documentFilter}
            SQL,
            $params,
        );

        $returns = (int) $this->connection->fetchOne(
            <<<SQL
            SELECT COUNT(*) FROM marketplace_returns
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND return_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
              {$documentFilter}
            SQL,
            $params,
        );

        $costs = (int) $this->connection->fetchOne(
            <<<SQL
            SELECT COUNT(*) FROM marketplace_costs
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND cost_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
              {$documentFilter}
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
        bool $force = false,
    ): array {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace->value,
            'periodFrom'  => $from->format('Y-m-d'),
            'periodTo'    => $to->format('Y-m-d'),
        ];

        $documentFilter = $force ? '' : 'AND document_id IS NULL';

        $sales = (int) $this->connection->executeStatement(
            <<<SQL
            DELETE FROM marketplace_sales
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND sale_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
              {$documentFilter}
            SQL,
            $params,
        );

        $returns = (int) $this->connection->executeStatement(
            <<<SQL
            DELETE FROM marketplace_returns
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND return_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
              {$documentFilter}
            SQL,
            $params,
        );

        $costs = (int) $this->connection->executeStatement(
            <<<SQL
            DELETE FROM marketplace_costs
            WHERE company_id = :companyId
              AND marketplace = :marketplace
              AND cost_date BETWEEN :periodFrom AND :periodTo
              AND raw_document_id IS NULL
              {$documentFilter}
            SQL,
            $params,
        );

        $this->logger->info('[DebugReprocessPeriod] Orphans deleted', [
            'company_id'  => $companyId,
            'marketplace' => $marketplace->value,
            'period_from' => $from->format('Y-m-d'),
            'period_to'   => $to->format('Y-m-d'),
            'force'       => $force,
            'sales'       => $sales,
            'returns'     => $returns,
            'costs'       => $costs,
        ]);

        return ['sales' => $sales, 'returns' => $returns, 'costs' => $costs];
    }

    /**
     * Переобрабатывает каждый raw-документ через ProcessMarketplaceRawDocumentAction
     * (та же логика, что и daily pipeline: classifier → processBatch).
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

            foreach (['sales', 'returns', 'costs'] as $step) {
                try {
                    $cmd = new ProcessMarketplaceRawDocumentCommand(
                        companyId: $companyId,
                        rawDocId: $doc['id'],
                        kind: $step,
                        forceReprocess: $step === 'costs',
                    );
                    $count = ($this->processAction)($cmd);
                    $result[$step] = ['status' => 'ok', 'processed' => $count];
                } catch (\Throwable $e) {
                    $result[$step] = ['status' => 'error', 'message' => $e->getMessage()];
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
