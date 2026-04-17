<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\SyncOzonReportMessage;
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
 * Каждое сообщение обрабатывается async worker'ом: загрузка из API +
 * автозапуск pipeline (sales/returns/costs).
 *
 *   GET /api/debug/reload-by-days
 *       ?companyId=UUID&marketplace=ozon&from=2026-01-01&to=2026-01-31
 *       [&preview=1]  — только план (по умолчанию)
 *       [&confirm=1]  — задиспатчить сообщения в очередь
 */
#[Route(
    path: '/api/debug/reload-by-days',
    name: 'api_debug_reload_by_days',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugReloadByDaysController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $companyId      = (string) $request->query->get('companyId', '');
        $marketplaceStr = (string) $request->query->get('marketplace', '');
        $fromStr        = (string) $request->query->get('from', '');
        $toStr          = (string) $request->query->get('to', '');
        $confirm        = (string) $request->query->get('confirm', '0') === '1';

        if ($companyId === '' || $marketplaceStr === '' || $fromStr === '' || $toStr === '') {
            return $this->json(['error' => 'companyId, marketplace, from, to are required'], 422);
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

        $days         = $this->buildDaysList($from, $to);
        $existingDays = $this->findExistingDays($companyId, $marketplace, $from, $to);
        $toDispatch   = array_filter($days, static fn (string $d): bool => !isset($existingDays[$d]));
        $skippedCount = count($days) - count($toDispatch);

        if (!$confirm) {
            return $this->json([
                'mode'        => 'preview',
                'companyId'   => $companyId,
                'marketplace' => $marketplace->value,
                'from'        => $from->format('Y-m-d'),
                'to'          => $to->format('Y-m-d'),
                'plan'        => [
                    'total_days'       => count($days),
                    'to_dispatch'      => count($toDispatch),
                    'skipped_existing' => $skippedCount,
                ],
            ]);
        }

        $dispatched = 0;

        foreach ($toDispatch as $dayStr) {
            $this->messageBus->dispatch(new SyncOzonReportMessage(
                companyId: $companyId,
                connectionId: $connectionId,
                date: $dayStr,
            ));
            $dispatched++;
        }

        $this->logger->info('[DebugReloadByDays] Messages dispatched', [
            'company_id'  => $companyId,
            'marketplace' => $marketplace->value,
            'from'        => $from->format('Y-m-d'),
            'to'          => $to->format('Y-m-d'),
            'dispatched'  => $dispatched,
            'skipped'     => $skippedCount,
        ]);

        return $this->json([
            'mode'             => 'executed',
            'companyId'        => $companyId,
            'marketplace'      => $marketplace->value,
            'from'             => $from->format('Y-m-d'),
            'to'               => $to->format('Y-m-d'),
            'dispatched_days'  => $dispatched,
            'skipped_existing' => $skippedCount,
            'note'             => 'Messages dispatched to async queue. Monitor workers for progress.',
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
