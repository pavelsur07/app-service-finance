<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

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
use App\Ingestion\Infrastructure\Api\Wildberries\WbFinanceReportClientInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class WbFinanceReportConnector implements SourceConnectorInterface
{
    public function __construct(
        private WbFinanceReportClientInterface $client,
        private ClockInterface $clock,
        private int $continuationDelaySeconds = 70,
    ) {
    }

    public function source(): IngestSource
    {
        return IngestSource::WILDBERRIES;
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
                name: 'Wildberries Seller',
                currency: 'RUB',
                metadata: ['connectionRef' => $connectionRef],
            ),
        ];
    }

    public function pull(PullRequest $request): PullResult
    {
        if (WbResourceType::FINANCE_SALES_REPORT_DETAILED !== $request->resourceType) {
            throw new \InvalidArgumentException(sprintf('Unsupported Wildberries resource type "%s".', $request->resourceType));
        }

        [$date, $rrdId] = $this->resolveCursor($request);
        $page = $this->client->fetchDetailedDayPage($request->companyId, $request->connectionRef, $date, $rrdId);
        $nextCursor = $page->hasMore && null !== $page->nextRrdId
            ? $this->encodeCursor($date, $page->nextRrdId)
            : $this->nextIncrementalDateCursor($request, $date, $page->hasMore);

        return new PullResult(
            rawBatch: new RawBatch(
                companyId: $request->companyId,
                connectionRef: $request->connectionRef,
                shopRef: $request->shopRef,
                source: IngestSource::WILDBERRIES,
                resourceType: WbResourceType::FINANCE_SALES_REPORT_DETAILED,
                externalId: sprintf('wb-sales-report-detailed:%s:rrd-%d', $date->format('Y-m-d'), $rrdId),
                syncJobId: $request->syncJobId,
                fetchedAt: new \DateTimeImmutable(),
                rows: $this->rowsOrEmptyMarker($page->rows, $page->metadata),
            ),
            nextCursorValue: $nextCursor,
            hasMore: $page->hasMore,
            normalizeRawRecords: false,
            continuationDelaySeconds: $page->hasMore ? $this->continuationDelaySeconds : null,
        );
    }

    public function push(PushRequest $request): PushResult
    {
        throw new UnsupportedCapabilityException('Wildberries finance connector does not support push operations.');
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: int}
     */
    private function resolveCursor(PullRequest $request): array
    {
        if (null !== $request->cursorValue && '' !== $request->cursorValue) {
            return $this->decodeCursor($request->cursorValue);
        }

        if (null !== $request->windowFrom) {
            $from = $request->windowFrom->setTime(0, 0);
            $to = ($request->windowTo ?? $request->windowFrom)->setTime(0, 0);
            if ($from != $to) {
                throw new \InvalidArgumentException('Wildberries finance report backfill expects one-day chunks.');
            }

            return [$from, 0];
        }

        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day')->setTime(0, 0);

        return [$yesterday, 0];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: int}
     */
    private function decodeCursor(string $cursorValue): array
    {
        try {
            $payload = json_decode($cursorValue, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }

        if (is_array($payload)) {
            $date = (string) ($payload['date'] ?? '');
            $rrdId = $payload['rrdId'] ?? 0;

            return [$this->date($date), $this->rrdId($rrdId)];
        }

        return [$this->date($cursorValue), 0];
    }

    private function encodeCursor(\DateTimeImmutable $date, int $rrdId): string
    {
        return json_encode([
            'date' => $date->format('Y-m-d'),
            'rrdId' => $rrdId,
        ], \JSON_THROW_ON_ERROR);
    }

    private function nextIncrementalDateCursor(PullRequest $request, \DateTimeImmutable $date, bool $hasMore): ?string
    {
        if ($hasMore || null !== $request->windowFrom || null !== $request->windowTo) {
            return null;
        }

        $nextDate = $date->modify('+1 day')->setTime(0, 0);
        $yesterday = $this->clock->now()->modify('-1 day')->setTime(0, 0);
        if ($nextDate > $yesterday) {
            return null;
        }

        return $nextDate->format('Y-m-d');
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Wildberries finance report cursor date must be YYYY-MM-DD.');
        }

        return $date;
    }

    private function rrdId(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new \InvalidArgumentException('Wildberries finance report cursor rrdId must be a non-negative integer.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $metadata
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOrEmptyMarker(array $rows, array $metadata): array
    {
        if ([] === $rows) {
            return [[
                '_ingestion_empty' => true,
                '_ingestion_resource' => WbResourceType::FINANCE_SALES_REPORT_DETAILED,
                '_ingestion_metadata' => $metadata,
            ]];
        }

        return array_map(
            static fn (array $row): array => $row + [
                '_ingestion_resource' => WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            ],
            $rows,
        );
    }
}
