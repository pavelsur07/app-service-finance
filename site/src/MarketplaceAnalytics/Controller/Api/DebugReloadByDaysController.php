<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\SyncOzonReportMessage;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @internal Debug controller, to be removed after data recovery.
 *
 * Диспатчит SyncOzonReportMessage для каждого дня в указанном периоде.
 * Поддерживает chunking: параметры offset/chunkSize для порционного dispatch.
 *
 *   GET /api/debug/reload-by-days
 *       ?marketplace=ozon&from=2026-01-01&to=2026-01-31
 *       [&confirm=1]         — задиспатчить сообщения в очередь
 *       [&chunkSize=30]      — сколько дней диспатчить за один вызов (default 30)
 *       [&offset=0]          — с какого дня начинать (для последовательных вызовов)
 */
#[Route(
    path: '/api/debug/reload-by-days',
    name: 'api_debug_reload_by_days',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugReloadByDaysController extends AbstractController
{
    private const DEFAULT_CHUNK_SIZE = 30;

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
        private readonly MessageBusInterface $messageBus,
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
        $chunkSize      = max(1, (int) $request->query->get('chunkSize', (string) self::DEFAULT_CHUNK_SIZE));
        $offset         = max(0, (int) $request->query->get('offset', '0'));

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

        $connectionId = $this->findConnectionId($companyId, $marketplace);
        if ($connectionId === null) {
            return $this->json(['error' => 'No active connection found for company ' . $companyId . ' on ' . $marketplace->value], 404);
        }

        $allDays        = $this->buildDaysList($from, $to);
        $existingDays   = $this->findExistingDays($companyId, $marketplace, $from, $to);
        $toDispatchAll  = array_values(array_filter($allDays, static fn (string $d): bool => !isset($existingDays[$d])));
        $skippedCount   = count($allDays) - count($toDispatchAll);
        $totalToDispatch = count($toDispatchAll);

        if (!$confirm) {
            return $this->json([
                'mode'               => 'preview',
                'companyId'          => $companyId,
                'marketplace'        => $marketplace->value,
                'from'               => $from->format('Y-m-d'),
                'to'                 => $to->format('Y-m-d'),
                'total_days'         => count($allDays),
                'skipped_existing'   => $skippedCount,
                'to_dispatch_total'  => $totalToDispatch,
                'suggested_chunks'   => $totalToDispatch > 0 ? (int) ceil($totalToDispatch / $chunkSize) : 0,
                'suggested_chunk_size' => $chunkSize,
            ]);
        }

        $chunk      = array_slice($toDispatchAll, $offset, $chunkSize);
        $dispatched = 0;

        foreach ($chunk as $dayStr) {
            $this->messageBus->dispatch(new SyncOzonReportMessage(
                companyId: $companyId,
                connectionId: $connectionId,
                date: $dayStr,
            ));
            $dispatched++;
        }

        $nextOffset = $offset + $chunkSize;
        $hasMore    = $nextOffset < $totalToDispatch;

        $this->logger->info('[DebugReloadByDays] Chunk dispatched', [
            'company_id'  => $companyId,
            'marketplace' => $marketplace->value,
            'offset'      => $offset,
            'chunk_size'  => $chunkSize,
            'dispatched'  => $dispatched,
            'has_more'    => $hasMore,
        ]);

        return $this->json([
            'mode'              => 'executed',
            'companyId'         => $companyId,
            'marketplace'       => $marketplace->value,
            'from'              => $from->format('Y-m-d'),
            'to'                => $to->format('Y-m-d'),
            'total_days'        => count($allDays),
            'skipped_existing'  => $skippedCount,
            'to_dispatch_total' => $totalToDispatch,
            'chunk'             => [
                'offset'     => $offset,
                'size'       => $chunkSize,
                'dispatched' => $dispatched,
            ],
            'next_offset' => $hasMore ? $nextOffset : null,
            'has_more'    => $hasMore,
            'hint'        => $hasMore
                ? sprintf('Call again with offset=%d to dispatch next chunk', $nextOffset)
                : 'All days dispatched',
        ]);
    }

    private function findConnectionId(string $companyId, MarketplaceType $marketplace): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM marketplace_connections
             WHERE company_id = :companyId AND marketplace = :mp AND is_active = true
             ORDER BY created_at ASC LIMIT 1',
            ['companyId' => $companyId, 'mp' => $marketplace->value],
        );

        return $id !== false ? (string) $id : null;
    }

    /**
     * @return list<string>
     */
    private function buildDaysList(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $days = [];
        $current = $from;

        while ($current <= $to) {
            $days[] = $current->format('Y-m-d');
            $current = $current->modify('+1 day');
        }

        return $days;
    }

    /**
     * @return array<string, true>
     */
    private function findExistingDays(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT period_from::text
             FROM marketplace_raw_documents
             WHERE company_id = :cid
               AND marketplace = :mp
               AND document_type = 'sales_report'
               AND period_from = period_to
               AND period_from >= :from
               AND period_to <= :to",
            [
                'cid'  => $companyId,
                'mp'   => $marketplace->value,
                'from' => $from->format('Y-m-d'),
                'to'   => $to->format('Y-m-d'),
            ],
        );

        return array_fill_keys($rows, true);
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
