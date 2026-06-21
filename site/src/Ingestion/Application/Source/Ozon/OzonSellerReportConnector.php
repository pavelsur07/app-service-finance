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
use App\Ingestion\Infrastructure\Api\Ozon\OzonAccrualClientInterface;

final readonly class OzonSellerReportConnector implements SourceConnectorInterface
{
    public function __construct(
        private OzonAccrualClientInterface $accrualClient,
        private int $chunkSizeDays = 7,
        private int $hotRewindDays = 14,
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
        return [
            new ShopDescriptor(
                externalId: $connectionRef,
                name: 'Ozon Seller',
                currency: 'RUB',
                metadata: ['connectionRef' => $connectionRef],
            ),
        ];
    }

    public function pull(PullRequest $request): PullResult
    {
        return match ($request->resourceType) {
            OzonResourceType::ACCRUAL_POSTINGS => $this->pullAccrualPostings($request),
            OzonResourceType::ACCRUAL_BY_DAY => $this->pullAccrualByDay($request),
            OzonResourceType::ACCRUAL_TYPES => $this->pullAccrualTypes($request),
            default => throw new \InvalidArgumentException(sprintf('Unsupported Ozon resource type "%s".', $request->resourceType)),
        };
    }

    public function push(PushRequest $request): PushResult
    {
        throw new UnsupportedCapabilityException('Ozon Seller connector does not support push operations.');
    }

    private function pullAccrualPostings(PullRequest $request): PullResult
    {
        throw new \LogicException('Ozon accrual postings require explicit posting_numbers and cannot be loaded by date backfill.');
    }

    private function pullAccrualByDay(PullRequest $request): PullResult
    {
        [$from, $to] = $this->resolveDailyWindow($request);
        $rows = [];
        $apiMetadata = [];

        foreach ($this->eachDay($from, $to) as $date) {
            $page = $this->accrualClient->fetchByDay(
                $request->companyId,
                $request->connectionRef,
                $date,
            );

            if ([] !== $page->rows) {
                array_push($rows, ...$page->rows);
            }

            $apiMetadata[] = [
                'date' => $date->format('Y-m-d'),
                'metadata' => $page->metadata,
            ];
        }

        $windowHasMore = null !== $request->windowTo && $to < $request->windowTo;
        $nextCursor = $windowHasMore || $this->isIncremental($request) ? $to->modify('+1 day')->format('Y-m-d') : null;

        return new PullResult(
            rawBatch: new RawBatch(
                companyId: $request->companyId,
                connectionRef: $request->connectionRef,
                shopRef: $request->shopRef,
                source: IngestSource::OZON,
                resourceType: OzonResourceType::ACCRUAL_BY_DAY,
                externalId: sprintf('accrual-by-day:%s:%s', $from->format('Y-m-d'), $to->format('Y-m-d')),
                syncJobId: $request->syncJobId,
                fetchedAt: new \DateTimeImmutable(),
                rows: $this->rowsOrEmptyMarker(
                    $rows,
                    OzonResourceType::ACCRUAL_BY_DAY,
                    [
                        'windowFrom' => $from->format('Y-m-d'),
                        'windowTo' => $to->format('Y-m-d'),
                        'apiMetadata' => $apiMetadata,
                    ],
                ),
            ),
            nextCursorValue: $nextCursor,
            hasMore: $windowHasMore,
        );
    }

    private function pullAccrualTypes(PullRequest $request): PullResult
    {
        $page = $this->accrualClient->fetchTypes($request->companyId, $request->connectionRef);

        return new PullResult(
            rawBatch: new RawBatch(
                companyId: $request->companyId,
                connectionRef: $request->connectionRef,
                shopRef: $request->shopRef,
                source: IngestSource::OZON,
                resourceType: OzonResourceType::ACCRUAL_TYPES,
                externalId: 'accrual-types',
                syncJobId: $request->syncJobId,
                fetchedAt: new \DateTimeImmutable(),
                rows: $this->rowsOrEmptyMarker(
                    $page->rows,
                    OzonResourceType::ACCRUAL_TYPES,
                    ['apiMetadata' => $page->metadata],
                ),
            ),
            nextCursorValue: null,
            hasMore: false,
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

        if (null === $request->windowTo && null !== $request->cursorValue && '' !== $request->cursorValue) {
            $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day')->setTime(23, 59, 59);
            if ($yesterday < $to) {
                $to = $yesterday;
            }
        }

        $to = $to->setTime(23, 59, 59);

        if ($from > $to) {
            throw new \InvalidArgumentException('Ozon daily report window is empty.');
        }

        return [$from, $to];
    }

    /**
     * @return \Generator<int, \DateTimeImmutable>
     */
    private function eachDay(\DateTimeImmutable $from, \DateTimeImmutable $to): \Generator
    {
        for ($date = $from->setTime(0, 0); $date <= $to; $date = $date->modify('+1 day')) {
            yield $date;
        }
    }

    private function isIncremental(PullRequest $request): bool
    {
        return null !== $request->cursorValue
            && '' !== $request->cursorValue
            && null === $request->windowFrom
            && null === $request->windowTo;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $metadata
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOrEmptyMarker(array $rows, string $resourceType, array $metadata): array
    {
        if ([] !== $rows) {
            return $rows;
        }

        return [[
            '_ingestion_empty' => true,
            '_ingestion_resource' => $resourceType,
            '_ingestion_metadata' => $metadata,
        ]];
    }
}
