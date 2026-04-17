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
 * Полное удаление данных компании на marketplace за период.
 * Удаляет sales, returns, costs и raw_documents.
 *
 *   GET /api/debug/wipe-company-data
 *       ?marketplace=ozon&from=2026-01-01&to=2026-04-17
 *       [&preview=1]  — только подсчёт (по умолчанию)
 *       [&confirm=1]  — выполнить удаление
 *       [&force=1]    — удалять записи с document_id IS NOT NULL
 */
#[Route(
    path: '/api/debug/wipe-company-data',
    name: 'api_debug_wipe_company_data',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugWipeCompanyDataController extends AbstractController
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

        $periodFrom = $from->format('Y-m-d');
        $periodTo   = $to->format('Y-m-d');

        if (!$confirm) {
            $counts        = $this->countRecords($companyId, $marketplace, $periodFrom, $periodTo, $force);
            $skippedClosed = $force ? ['sales' => 0, 'returns' => 0, 'costs' => 0, 'raw_documents' => 0] : $this->countClosed($companyId, $marketplace, $periodFrom, $periodTo);

            return $this->json([
                'mode'           => 'preview',
                'companyId'      => $companyId,
                'marketplace'    => $marketplace->value,
                'from'           => $periodFrom,
                'to'             => $periodTo,
                'force'          => $force,
                'counts'         => $counts,
                'skipped_closed' => $skippedClosed,
            ]);
        }

        $this->connection->beginTransaction();

        try {
            $counts        = $this->deleteRecords($companyId, $marketplace, $periodFrom, $periodTo, $force);
            $skippedClosed = $force ? ['sales' => 0, 'returns' => 0, 'costs' => 0, 'raw_documents' => 0] : $this->countClosed($companyId, $marketplace, $periodFrom, $periodTo);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $this->logger->info('[DebugWipe] Company data wiped', [
            'company_id'  => $companyId,
            'marketplace' => $marketplace->value,
            'from'        => $periodFrom,
            'to'          => $periodTo,
            'force'       => $force,
            'counts'      => $counts,
        ]);

        return $this->json([
            'mode'           => 'executed',
            'companyId'      => $companyId,
            'marketplace'    => $marketplace->value,
            'from'           => $periodFrom,
            'to'             => $periodTo,
            'force'          => $force,
            'counts'         => $counts,
            'skipped_closed' => $skippedClosed,
        ]);
    }

    /**
     * @return array{sales: int, returns: int, costs: int, raw_documents: int}
     */
    private function countRecords(string $companyId, MarketplaceType $marketplace, string $from, string $to, bool $force): array
    {
        $documentFilter = $force ? '' : 'AND document_id IS NULL';
        $mp = $marketplace->value;

        $sales = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM marketplace_sales
             WHERE company_id = :cid AND marketplace = :mp AND sale_date BETWEEN :from AND :to {$documentFilter}",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
        );

        $returns = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM marketplace_returns
             WHERE company_id = :cid AND marketplace = :mp AND return_date BETWEEN :from AND :to {$documentFilter}",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
        );

        $costs = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM marketplace_costs
             WHERE company_id = :cid AND marketplace = :mp AND cost_date BETWEEN :from AND :to {$documentFilter}",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
        );

        $rawDocs = $force
            ? (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM marketplace_raw_documents
                 WHERE company_id = :cid AND marketplace = :mp AND period_from >= :from AND period_to <= :to",
                ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
            )
            : 0;

        return ['sales' => $sales, 'returns' => $returns, 'costs' => $costs, 'raw_documents' => $rawDocs];
    }

    /**
     * @return array{sales: int, returns: int, costs: int, raw_documents: int}
     */
    private function countClosed(string $companyId, MarketplaceType $marketplace, string $from, string $to): array
    {
        $mp = $marketplace->value;

        $sales = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM marketplace_sales
             WHERE company_id = :cid AND marketplace = :mp AND sale_date BETWEEN :from AND :to AND document_id IS NOT NULL",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
        );

        $returns = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM marketplace_returns
             WHERE company_id = :cid AND marketplace = :mp AND return_date BETWEEN :from AND :to AND document_id IS NOT NULL",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
        );

        $costs = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM marketplace_costs
             WHERE company_id = :cid AND marketplace = :mp AND cost_date BETWEEN :from AND :to AND document_id IS NOT NULL",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
        );

        $rawDocs = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM marketplace_raw_documents
             WHERE company_id = :cid AND marketplace = :mp AND period_from >= :from AND period_to <= :to",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
        );

        return ['sales' => $sales, 'returns' => $returns, 'costs' => $costs, 'raw_documents' => $rawDocs];
    }

    /**
     * @return array{sales: int, returns: int, costs: int, raw_documents: int}
     */
    private function deleteRecords(string $companyId, MarketplaceType $marketplace, string $from, string $to, bool $force): array
    {
        $documentFilter = $force ? '' : 'AND document_id IS NULL';
        $mp = $marketplace->value;

        $sales = (int) $this->connection->executeStatement(
            "DELETE FROM marketplace_sales
             WHERE company_id = :cid AND marketplace = :mp AND sale_date BETWEEN :from AND :to {$documentFilter}",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
        );

        $returns = (int) $this->connection->executeStatement(
            "DELETE FROM marketplace_returns
             WHERE company_id = :cid AND marketplace = :mp AND return_date BETWEEN :from AND :to {$documentFilter}",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
        );

        $costs = (int) $this->connection->executeStatement(
            "DELETE FROM marketplace_costs
             WHERE company_id = :cid AND marketplace = :mp AND cost_date BETWEEN :from AND :to {$documentFilter}",
            ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
        );

        $rawDocs = $force
            ? (int) $this->connection->executeStatement(
                "DELETE FROM marketplace_raw_documents
                 WHERE company_id = :cid AND marketplace = :mp AND period_from >= :from AND period_to <= :to",
                ['cid' => $companyId, 'mp' => $mp, 'from' => $from, 'to' => $to],
            )
            : 0;

        return ['sales' => $sales, 'returns' => $returns, 'costs' => $costs, 'raw_documents' => $rawDocs];
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
