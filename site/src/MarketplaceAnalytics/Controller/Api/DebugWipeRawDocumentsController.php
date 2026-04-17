<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

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
 * @internal Debug controller, to be removed after data recovery.
 *
 * Удаляет только raw-документы (marketplace_raw_documents), не трогая
 * sales/returns/costs. После удаления показывает orphaned records.
 *
 *   GET /api/debug/wipe-raw-documents
 *       ?marketplace=ozon&from=2026-01-01&to=2026-04-17
 *       [&confirm=1]
 *       [&mode=overlapping]  — contained | overlapping (default: overlapping)
 */
#[Route(
    path: '/api/debug/wipe-raw-documents',
    name: 'api_debug_wipe_raw_documents',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugWipeRawDocumentsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $marketplaceStr = (string) $request->query->get('marketplace', '');
        $fromStr        = (string) $request->query->get('from', '');
        $toStr          = (string) $request->query->get('to', '');
        $confirm        = (string) $request->query->get('confirm', '0') === '1';
        $modeStr        = (string) $request->query->get('mode', 'overlapping');

        if ($marketplaceStr === '' || $fromStr === '' || $toStr === '') {
            return $this->json(['error' => 'marketplace, from, to are required'], 422);
        }

        if (!in_array($modeStr, ['contained', 'overlapping'], true)) {
            return $this->json(['error' => 'mode must be "contained" or "overlapping"'], 422);
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

        $periodFrom = $from->format('Y-m-d');
        $periodTo   = $to->format('Y-m-d');

        $periodCondition = $modeStr === 'overlapping'
            ? 'AND mrd.period_from <= :periodTo AND mrd.period_to >= :periodFrom'
            : 'AND mrd.period_from >= :periodFrom AND mrd.period_to <= :periodTo';

        $sample = $this->fetchSample($companyId, $marketplace, $periodFrom, $periodTo, $periodCondition);
        $count  = count($sample);

        if (!$confirm) {
            return $this->json([
                'mode'                    => 'preview',
                'raw_docs_mode'           => $modeStr,
                'companyId'               => $companyId,
                'marketplace'             => $marketplace->value,
                'from'                    => $periodFrom,
                'to'                      => $periodTo,
                'raw_documents_to_delete' => $count,
                'sample'                  => $sample,
            ]);
        }

        $deleted = $this->deleteRawDocuments($companyId, $marketplace, $periodFrom, $periodTo, $periodCondition);
        $orphans = $this->countOrphanedRecords($companyId, $marketplace, $periodFrom, $periodTo);

        $this->logger->info('[DebugWipeRawDocuments] Raw documents deleted', [
            'company_id'  => $companyId,
            'marketplace' => $marketplace->value,
            'from'        => $periodFrom,
            'to'          => $periodTo,
            'mode'        => $modeStr,
            'deleted'     => $deleted,
        ]);

        return $this->json([
            'mode'                          => 'executed',
            'raw_docs_mode'                 => $modeStr,
            'companyId'                     => $companyId,
            'marketplace'                   => $marketplace->value,
            'from'                          => $periodFrom,
            'to'                            => $periodTo,
            'raw_documents_deleted'         => $deleted,
            'orphaned_records_after_delete' => $orphans,
        ]);
    }

    /**
     * @return list<array{id: string, period: string, synced_at: string, records_count: int, document_type: string, processing_status: string|null}>
     */
    private function fetchSample(
        string $companyId,
        MarketplaceType $marketplace,
        string $periodFrom,
        string $periodTo,
        string $periodCondition,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT mrd.id,
                    mrd.period_from::text AS period_from,
                    mrd.period_to::text   AS period_to,
                    mrd.synced_at::text   AS synced_at,
                    mrd.records_count,
                    mrd.document_type,
                    mrd.processing_status
             FROM marketplace_raw_documents mrd
             WHERE mrd.company_id = :companyId
               AND mrd.marketplace = :marketplace
               {$periodCondition}
             ORDER BY mrd.period_from, mrd.synced_at",
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace->value,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        return array_map(
            static fn (array $r): array => [
                'id'                => (string) $r['id'],
                'period'            => $r['period_from'] . ' — ' . $r['period_to'],
                'synced_at'         => (string) $r['synced_at'],
                'records_count'     => (int) $r['records_count'],
                'document_type'     => (string) $r['document_type'],
                'processing_status' => $r['processing_status'],
            ],
            $rows,
        );
    }

    private function deleteRawDocuments(
        string $companyId,
        MarketplaceType $marketplace,
        string $periodFrom,
        string $periodTo,
        string $periodCondition,
    ): int {
        $sql = str_replace('mrd.', '', $periodCondition);

        return (int) $this->connection->executeStatement(
            "DELETE FROM marketplace_raw_documents mrd
             WHERE mrd.company_id = :companyId
               AND mrd.marketplace = :marketplace
               {$periodCondition}",
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace->value,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );
    }

    /**
     * @return array{sales: int, returns: int, costs: int}
     */
    private function countOrphanedRecords(
        string $companyId,
        MarketplaceType $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $mp = $marketplace->value;

        $sales = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM marketplace_sales ms
             WHERE ms.company_id = :cid AND ms.marketplace = :mp
               AND ms.sale_date BETWEEN :from AND :to
               AND ms.raw_document_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM marketplace_raw_documents mrd WHERE mrd.id = ms.raw_document_id)",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $periodFrom, 'to' => $periodTo],
        );

        $returns = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM marketplace_returns mr
             WHERE mr.company_id = :cid AND mr.marketplace = :mp
               AND mr.return_date BETWEEN :from AND :to
               AND mr.raw_document_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM marketplace_raw_documents mrd WHERE mrd.id = mr.raw_document_id)",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $periodFrom, 'to' => $periodTo],
        );

        $costs = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM marketplace_costs mc
             WHERE mc.company_id = :cid AND mc.marketplace = :mp
               AND mc.cost_date BETWEEN :from AND :to
               AND mc.raw_document_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM marketplace_raw_documents mrd WHERE mrd.id = mc.raw_document_id)",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $periodFrom, 'to' => $periodTo],
        );

        return ['sales' => $sales, 'returns' => $returns, 'costs' => $costs];
    }

    private function parseStrictDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }
}
