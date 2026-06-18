<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PullResult;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\DTO\PushResult;
use App\Ingestion\Application\DTO\ShopDescriptor;
use App\Ingestion\Domain\Contract\SourceConnectorInterface;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\UnsupportedCapabilityException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonClientAdapterInterface;
use Psr\Log\LoggerInterface;

final readonly class OzonSellerReportConnector implements SourceConnectorInterface
{
    private const PAGE_SIZE = 1000;
    private const MAX_PAGES_PER_PULL = 10;

    public function __construct(
        private OzonClientAdapterInterface $client,
        private LoggerInterface $logger,
        private int $chunkSizeDays = 7,
        private int $hotRewindDays = 14,
        private int $rateLimitRpm = 60,
    ) {
    }

    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return [Capability::CAN_DISCOVER_SHOPS, Capability::CAN_PULL];
    }

    /**
     * @return list<ShopDescriptor>
     */
    public function discoverShops(string $companyId, string $connectionRef): array
    {
        return array_map(
            static fn ($shop): ShopDescriptor => new ShopDescriptor(
                externalId: $shop->externalId,
                name: $shop->name,
                currency: $shop->currency,
                metadata: $shop->metadata,
            ),
            $this->client->listClusters($companyId, $connectionRef),
        );
    }

    public function pull(PullRequest $request): PullResult
    {
        return match ($request->resourceType) {
            OzonResourceType::DAILY_REPORT => $this->pullDailyReport($request),
            OzonResourceType::REALIZATION => $this->pullRealization($request),
            default => throw new \InvalidArgumentException(sprintf('Unsupported Ozon resource type "%s".', $request->resourceType)),
        };
    }

    public function push(PushRequest $request): PushResult
    {
        throw new UnsupportedCapabilityException('Ozon Seller connector does not support push operations.');
    }

    private function pullDailyReport(PullRequest $request): PullResult
    {
        [$from, $to] = $this->resolveDailyWindow($request);
        $rows = [];
        $page = 1;
        $hasRemoteMore = false;

        do {
            $pageResult = $this->client->fetchTransactionList(
                $request->companyId,
                $request->connectionRef,
                $from,
                $to,
                $page,
                self::PAGE_SIZE,
            );

            if ([] !== $pageResult->rows) {
                array_push($rows, ...$pageResult->rows);
            }

            $hasRemoteMore = $pageResult->hasMore;
            ++$page;
        } while ($hasRemoteMore && $page <= self::MAX_PAGES_PER_PULL);

        if ($hasRemoteMore) {
            $this->logger->warning('Ozon daily report pagination cap reached.', [
                'companyId' => $request->companyId,
                'connectionRef' => $request->connectionRef,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'maxPages' => self::MAX_PAGES_PER_PULL,
            ]);

            throw new \RuntimeException('Ozon daily report pagination cap reached before all rows were fetched.');
        }

        $windowHasMore = null !== $request->windowTo && $to < $request->windowTo;
        $nextCursor = $windowHasMore ? $to->modify('+1 day')->format('Y-m-d') : null;

        return new PullResult(
            rawBatch: new RawBatch(
                companyId: $request->companyId,
                connectionRef: $request->connectionRef,
                shopRef: $request->shopRef,
                source: IngestSource::OZON,
                resourceType: OzonResourceType::DAILY_REPORT,
                externalId: sprintf('daily:%s:%s', $from->format('Y-m-d'), $to->format('Y-m-d')),
                syncJobId: $request->syncJobId,
                fetchedAt: new \DateTimeImmutable(),
                rows: $rows,
            ),
            nextCursorValue: $nextCursor,
            hasMore: $windowHasMore,
        );
    }

    private function pullRealization(PullRequest $request): PullResult
    {
        $monthStart = $this->resolveRealizationMonth($request);
        $page = $this->client->fetchRealization(
            $request->companyId,
            $request->connectionRef,
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('n'),
        );

        $rows = array_map(
            static fn (array $row): array => $row + ['_header' => $page->metadata['header'] ?? [], '_header_additional' => $page->metadata['headerAdditional'] ?? []],
            $page->rows,
        );

        $nextMonth = $monthStart->modify('first day of next month');
        $windowHasMore = null !== $request->windowTo && $nextMonth <= $request->windowTo;

        return new PullResult(
            rawBatch: new RawBatch(
                companyId: $request->companyId,
                connectionRef: $request->connectionRef,
                shopRef: $request->shopRef,
                source: IngestSource::OZON,
                resourceType: OzonResourceType::REALIZATION,
                externalId: sprintf('realization:%s', $monthStart->format('Y-m')),
                syncJobId: $request->syncJobId,
                fetchedAt: new \DateTimeImmutable(),
                rows: $rows,
            ),
            nextCursorValue: $windowHasMore ? $nextMonth->format('Y-m-01') : null,
            hasMore: $windowHasMore,
        );
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveDailyWindow(PullRequest $request): array
    {
        $from = null !== $request->cursorValue && '' !== $request->cursorValue
            ? new \DateTimeImmutable($request->cursorValue)
            : ($request->windowFrom ?? new \DateTimeImmutable(sprintf('-%d days', $this->hotRewindDays)));
        $from = $from->setTime(0, 0);

        $maxTo = $from->modify(sprintf('+%d days', max(1, $this->chunkSizeDays) - 1));
        $to = null !== $request->windowTo && $request->windowTo < $maxTo
            ? $request->windowTo
            : $maxTo;
        $to = $to->setTime(23, 59, 59);

        if ($from > $to) {
            throw new \InvalidArgumentException('Ozon daily report window is empty.');
        }

        return [$from, $to];
    }

    private function resolveRealizationMonth(PullRequest $request): \DateTimeImmutable
    {
        $date = null !== $request->cursorValue && '' !== $request->cursorValue
            ? new \DateTimeImmutable($request->cursorValue)
            : ($request->windowFrom ?? new \DateTimeImmutable('first day of previous month'));

        return $date->modify('first day of this month')->setTime(0, 0);
    }
}
