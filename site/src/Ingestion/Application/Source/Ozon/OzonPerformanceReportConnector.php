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
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\UnsupportedCapabilityException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonPerformanceCampaignNotFoundException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonPerformanceReportClientInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;
use Symfony\Component\Clock\ClockInterface;

final readonly class OzonPerformanceReportConnector implements SourceConnectorInterface
{
    private const CAMPAIGN_BATCH_SIZE = 10;
    private const SEARCH_PROMO_POLL_DELAY_SECONDS = 60;
    private const SEARCH_PROMO_POLL_MAX_ATTEMPTS = 60;
    private const SEARCH_PROMO_POLL_TIMEOUT_SECONDS = 3600;

    private const ADV_OBJECT_TYPE_SKU = 'SKU';
    private const ADV_OBJECT_TYPE_SEARCH_PROMO = 'SEARCH_PROMO';

    private const SEARCH_PROMO_REPORT_PRODUCTS = 'products';
    private const SEARCH_PROMO_REPORT_ORDERS = 'orders';

    public function __construct(
        private OzonPerformanceReportClientInterface $client,
        private ClockInterface $clock,
    ) {
    }

    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    /**
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return [
            OzonResourceType::PERFORMANCE_CAMPAIGNS,
            OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS,
            OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS,
            OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS,
            OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
            OzonResourceType::PERFORMANCE_EXPENSE_STATISTICS,
        ];
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
                name: 'Ozon Performance',
                currency: 'RUB',
                metadata: ['connectionRef' => $connectionRef],
            ),
        ];
    }

    public function pull(PullRequest $request): PullResult
    {
        return match ($request->resourceType) {
            OzonResourceType::PERFORMANCE_CAMPAIGNS => $this->pullCampaigns($request),
            OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS => $this->pullSkuCampaignObjects($request),
            OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS => $this->pullSearchPromoProducts($request),
            OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS => $this->pullSkuProductStatistics($request),
            OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS => $this->pullSearchPromoStatistics($request),
            OzonResourceType::PERFORMANCE_EXPENSE_STATISTICS => $this->pullExpenseStatistics($request),
            default => throw new \InvalidArgumentException(sprintf('Unsupported Ozon Performance resource type "%s".', $request->resourceType)),
        };
    }

    public function push(PushRequest $request): PushResult
    {
        throw new UnsupportedCapabilityException('Ozon Performance connector does not support push operations.');
    }

    private function pullCampaigns(PullRequest $request): PullResult
    {
        $page = $this->client->listCampaigns($request->companyId, $request->connectionRef, [
            self::ADV_OBJECT_TYPE_SKU,
            self::ADV_OBJECT_TYPE_SEARCH_PROMO,
        ]);

        return $this->doneResult($request, $page, OzonResourceType::PERFORMANCE_CAMPAIGNS, 'performance-campaigns');
    }

    private function pullSkuCampaignObjects(PullRequest $request): PullResult
    {
        $campaigns = $this->campaignIds($request, self::ADV_OBJECT_TYPE_SKU);
        if ([] === $campaigns) {
            return $this->doneEmptyResult($request, OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS, 'performance-sku-objects:empty');
        }

        $cursor = $this->cursor($request->cursorValue, ['campaignOffset' => 0]);
        $offset = $this->cursorInt($cursor, 'campaignOffset');
        $campaignId = $campaigns[$offset] ?? null;
        if (null === $campaignId) {
            return $this->doneEmptyResult($request, OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS, 'performance-sku-objects:done');
        }

        $skippedReason = null;
        try {
            $page = $this->client->fetchCampaignObjects($request->companyId, $request->connectionRef, $campaignId);
            $rows = $page->rows;
            $apiMetadata = $page->metadata;
        } catch (OzonPerformanceCampaignNotFoundException $exception) {
            $rows = [];
            $skippedReason = 'campaign_not_found';
            $apiMetadata = [
                'endpoint' => $exception->endpoint,
                'campaignId' => $exception->campaignId,
                'skippedReason' => $skippedReason,
                'responseBody' => $exception->responseBody,
            ];
        }

        $nextOffset = $offset + 1;
        $hasMore = $nextOffset < count($campaigns);
        $metadata = [
            'apiMetadata' => $apiMetadata,
            'campaignId' => $campaignId,
            'campaignOffset' => $offset,
            'campaignCount' => count($campaigns),
        ];
        if (null !== $skippedReason) {
            $metadata['skippedReason'] = $skippedReason;
        }

        return $this->rawResult(
            request: $request,
            resourceType: OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS,
            externalId: sprintf('performance-sku-objects:%s:%s', $campaignId, $this->windowId($request)),
            rows: $rows,
            metadata: $metadata,
            nextCursor: $hasMore ? $this->encodeCursor(['campaignOffset' => $nextOffset]) : null,
            hasMore: $hasMore,
        );
    }

    private function pullSearchPromoProducts(PullRequest $request): PullResult
    {
        $campaigns = $this->campaignIds($request, self::ADV_OBJECT_TYPE_SEARCH_PROMO);
        if ([] === $campaigns) {
            return $this->doneEmptyResult($request, OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS, 'performance-search-promo-products:empty');
        }

        $cursor = $this->cursor($request->cursorValue, ['campaignOffset' => 0, 'page' => 1]);
        $offset = $this->cursorInt($cursor, 'campaignOffset');
        $pageNumber = max(1, $this->cursorInt($cursor, 'page', 1));
        $campaignId = $campaigns[$offset] ?? null;
        if (null === $campaignId) {
            return $this->doneEmptyResult($request, OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS, 'performance-search-promo-products:done');
        }

        $page = $this->client->fetchSearchPromoProducts($request->companyId, $request->connectionRef, $campaignId, $pageNumber);
        $nextCursor = null;
        if ($page->hasMore) {
            $nextCursor = $this->encodeCursor(['campaignOffset' => $offset, 'page' => $pageNumber + 1]);
        } elseif ($offset + 1 < count($campaigns)) {
            $nextCursor = $this->encodeCursor(['campaignOffset' => $offset + 1, 'page' => 1]);
        }

        return $this->rawResult(
            request: $request,
            resourceType: OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS,
            externalId: sprintf('performance-search-promo-products:%s:%s:page:%d', $campaignId, $this->windowId($request), $pageNumber),
            rows: $page->rows,
            metadata: [
                'apiMetadata' => $page->metadata,
                'campaignId' => $campaignId,
                'campaignOffset' => $offset,
                'campaignCount' => count($campaigns),
                'page' => $pageNumber,
            ],
            nextCursor: $nextCursor,
            hasMore: null !== $nextCursor,
        );
    }

    private function pullSkuProductStatistics(PullRequest $request): PullResult
    {
        [$from, $to] = $this->window($request);
        $campaigns = $this->campaignIds($request, self::ADV_OBJECT_TYPE_SKU);
        if ([] === $campaigns) {
            return $this->doneEmptyResult($request, OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS, 'performance-sku-stats:empty');
        }

        $cursor = $this->cursor($request->cursorValue, ['batchOffset' => 0]);
        $batchOffset = $this->cursorInt($cursor, 'batchOffset');
        $campaignBatch = array_slice($campaigns, $batchOffset, self::CAMPAIGN_BATCH_SIZE);
        if ([] === $campaignBatch) {
            return $this->doneEmptyResult($request, OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS, 'performance-sku-stats:done');
        }

        $page = $this->client->fetchSkuProductStatistics($request->companyId, $request->connectionRef, $from, $to, $campaignBatch);
        $nextOffset = $batchOffset + self::CAMPAIGN_BATCH_SIZE;
        $hasMore = $nextOffset < count($campaigns);

        return $this->rawResult(
            request: $request,
            resourceType: OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS,
            externalId: sprintf('performance-sku-stats:%s:%s:batch:%s', $from->format('Y-m-d'), $to->format('Y-m-d'), $this->campaignBatchHash($campaignBatch)),
            rows: $page->rows,
            metadata: [
                'apiMetadata' => $page->metadata,
                'campaignIds' => $campaignBatch,
                'batchOffset' => $batchOffset,
                'campaignCount' => count($campaigns),
                'windowFrom' => $from->format('Y-m-d'),
                'windowTo' => $to->format('Y-m-d'),
            ],
            nextCursor: $hasMore ? $this->encodeCursor(['batchOffset' => $nextOffset]) : null,
            hasMore: $hasMore,
        );
    }

    private function pullSearchPromoStatistics(PullRequest $request): PullResult
    {
        [$from, $to] = $this->window($request);
        $campaigns = $this->campaignIds($request, self::ADV_OBJECT_TYPE_SEARCH_PROMO);
        if ([] === $campaigns) {
            return $this->doneEmptyResult($request, OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS, 'performance-search-promo-stats:empty');
        }

        $cursor = $this->cursor($request->cursorValue, [
            'batchOffset' => 0,
            'reportType' => self::SEARCH_PROMO_REPORT_PRODUCTS,
            'state' => 'request',
            'pollAttempts' => 0,
            'pollStartedAt' => $this->clock->now()->format(\DATE_ATOM),
        ]);
        $batchOffset = $this->cursorInt($cursor, 'batchOffset');
        $reportType = $this->searchPromoReportType($cursor['reportType'] ?? self::SEARCH_PROMO_REPORT_PRODUCTS);
        $campaignBatch = array_slice($campaigns, $batchOffset, self::CAMPAIGN_BATCH_SIZE);
        if ([] === $campaignBatch) {
            return $this->doneEmptyResult($request, OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS, 'performance-search-promo-stats:done');
        }

        if ('poll' !== ($cursor['state'] ?? 'request')) {
            $uuid = $this->client->generateSearchPromoReport($request->companyId, $request->connectionRef, $reportType, $from, $to, $campaignBatch);

            return new PullResult(
                rawBatch: null,
                nextCursorValue: $this->encodeCursor([
                    'batchOffset' => $batchOffset,
                    'reportType' => $reportType,
                    'state' => 'poll',
                    'uuid' => $uuid,
                    'pollAttempts' => 0,
                    'pollStartedAt' => $this->clock->now()->format(\DATE_ATOM),
                ]),
                hasMore: true,
                normalizeRawRecords: false,
                continuationDelaySeconds: self::SEARCH_PROMO_POLL_DELAY_SECONDS,
            );
        }

        $uuid = $this->cursorString($cursor, 'uuid');
        if ('' === $uuid) {
            throw new \InvalidArgumentException('Ozon Search Promo statistics cursor does not contain report uuid.');
        }

        $reportLink = $this->client->pollReport($request->companyId, $request->connectionRef, $uuid);
        if (null === $reportLink) {
            $nextPollAttempts = $this->cursorInt($cursor, 'pollAttempts') + 1;
            $pollStartedAt = $this->cursorDate($cursor, 'pollStartedAt') ?? $this->clock->now();
            $elapsedSeconds = $this->clock->now()->getTimestamp() - $pollStartedAt->getTimestamp();
            if ($nextPollAttempts > self::SEARCH_PROMO_POLL_MAX_ATTEMPTS || $elapsedSeconds >= self::SEARCH_PROMO_POLL_TIMEOUT_SECONDS) {
                throw new ConnectorTransientException(sprintf(
                    'Ozon Search Promo report %s was not ready after %d attempts and %d seconds.',
                    $uuid,
                    $nextPollAttempts,
                    max(0, $elapsedSeconds),
                ));
            }

            return new PullResult(
                rawBatch: null,
                nextCursorValue: $this->encodeCursor([
                    'batchOffset' => $batchOffset,
                    'reportType' => $reportType,
                    'state' => 'poll',
                    'uuid' => $uuid,
                    'pollAttempts' => $nextPollAttempts,
                    'pollStartedAt' => $pollStartedAt->format(\DATE_ATOM),
                ]),
                hasMore: true,
                normalizeRawRecords: false,
                continuationDelaySeconds: self::SEARCH_PROMO_POLL_DELAY_SECONDS,
            );
        }

        $page = $this->client->downloadReport($request->companyId, $request->connectionRef, $uuid, $reportLink);
        $nextCursor = $this->nextSearchPromoStatisticsCursor($reportType, $batchOffset, count($campaigns));

        return $this->rawResult(
            request: $request,
            resourceType: OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
            externalId: sprintf(
                'performance-search-promo-stats:%s:%s:%s:batch:%s',
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
                $reportType,
                $this->campaignBatchHash($campaignBatch),
            ),
            rows: $page->rows,
            metadata: [
                'apiMetadata' => $page->metadata,
                'campaignIds' => $campaignBatch,
                'batchOffset' => $batchOffset,
                'campaignCount' => count($campaigns),
                'reportType' => $reportType,
                'reportUuid' => $uuid,
                'windowFrom' => $from->format('Y-m-d'),
                'windowTo' => $to->format('Y-m-d'),
            ],
            nextCursor: $nextCursor,
            hasMore: null !== $nextCursor,
        );
    }

    private function pullExpenseStatistics(PullRequest $request): PullResult
    {
        [$from, $to] = $this->window($request);
        $page = $this->client->fetchExpenseStatistics($request->companyId, $request->connectionRef, $from, $to);

        return $this->rawResult(
            request: $request,
            resourceType: OzonResourceType::PERFORMANCE_EXPENSE_STATISTICS,
            externalId: sprintf('performance-expense-stats:%s:%s', $from->format('Y-m-d'), $to->format('Y-m-d')),
            rows: $page->rows,
            metadata: [
                'apiMetadata' => $page->metadata,
                'windowFrom' => $from->format('Y-m-d'),
                'windowTo' => $to->format('Y-m-d'),
            ],
            nextCursor: null,
            hasMore: false,
        );
    }

    private function doneResult(PullRequest $request, OzonRawPage $page, string $resourceType, string $externalIdPrefix): PullResult
    {
        return $this->rawResult(
            request: $request,
            resourceType: $resourceType,
            externalId: sprintf('%s:%s', $externalIdPrefix, $this->windowId($request)),
            rows: $page->rows,
            metadata: ['apiMetadata' => $page->metadata],
            nextCursor: null,
            hasMore: false,
        );
    }

    private function doneEmptyResult(PullRequest $request, string $resourceType, string $externalId): PullResult
    {
        return $this->rawResult(
            request: $request,
            resourceType: $resourceType,
            externalId: $externalId.':'.$this->windowId($request),
            rows: [],
            metadata: [],
            nextCursor: null,
            hasMore: false,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $metadata
     */
    private function rawResult(
        PullRequest $request,
        string $resourceType,
        string $externalId,
        array $rows,
        array $metadata,
        ?string $nextCursor,
        bool $hasMore,
    ): PullResult {
        return new PullResult(
            rawBatch: new RawBatch(
                companyId: $request->companyId,
                connectionRef: $request->connectionRef,
                shopRef: $request->shopRef,
                source: IngestSource::OZON,
                resourceType: $resourceType,
                externalId: $externalId,
                syncJobId: $request->syncJobId,
                fetchedAt: new \DateTimeImmutable(),
                rows: $this->rowsOrEmptyMarker($rows, $resourceType, $metadata),
            ),
            nextCursorValue: $nextCursor,
            hasMore: $hasMore,
            normalizeRawRecords: false,
        );
    }

    /**
     * @return list<string>
     */
    private function campaignIds(PullRequest $request, string $advObjectType): array
    {
        $page = $this->client->listCampaigns($request->companyId, $request->connectionRef, [$advObjectType]);
        $campaignIds = [];

        foreach ($page->rows as $row) {
            $id = $this->campaignId($row);
            if ('' !== $id) {
                $campaignIds[] = $id;
            }
        }

        sort($campaignIds);

        return array_values(array_unique($campaignIds));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function campaignId(array $row): string
    {
        foreach (['id', 'campaignId', 'campaign_id', 'campaignID'] as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && '' !== trim((string) $value)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function window(PullRequest $request): array
    {
        $to = ($request->windowTo ?? $this->clock->now()->modify('-1 day'))->setTime(23, 59, 59);
        $from = ($request->windowFrom ?? $to->modify('-14 days'))->setTime(0, 0);
        if ($from > $to) {
            throw new \InvalidArgumentException('Ozon Performance window is empty.');
        }

        return [$from, $to];
    }

    private function windowId(PullRequest $request): string
    {
        [$from, $to] = $this->window($request);

        return sprintf('%s:%s', $from->format('Y-m-d'), $to->format('Y-m-d'));
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function cursor(?string $cursorValue, array $defaults): array
    {
        if (null === $cursorValue || '' === $cursorValue) {
            return $defaults;
        }

        try {
            $decoded = json_decode($cursorValue, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Ozon Performance cursor is not valid JSON.', previous: $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('Ozon Performance cursor must be a JSON object.');
        }

        return $decoded + $defaults;
    }

    /**
     * @param array<string, mixed> $cursor
     */
    private function cursorInt(array $cursor, string $key, int $default = 0): int
    {
        $value = $cursor[$key] ?? $default;
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && ctype_digit($value)) {
            return max(0, (int) $value);
        }

        return max(0, $default);
    }

    /**
     * @param array<string, mixed> $cursor
     */
    private function cursorString(array $cursor, string $key): string
    {
        $value = $cursor[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $cursor
     */
    private function cursorDate(array $cursor, string $key): ?\DateTimeImmutable
    {
        $value = $cursor[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $cursor
     */
    private function encodeCursor(array $cursor): string
    {
        return json_encode($cursor, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private function searchPromoReportType(mixed $value): string
    {
        $reportType = is_scalar($value) ? (string) $value : self::SEARCH_PROMO_REPORT_PRODUCTS;

        return match ($reportType) {
            self::SEARCH_PROMO_REPORT_PRODUCTS, self::SEARCH_PROMO_REPORT_ORDERS => $reportType,
            default => throw new \InvalidArgumentException(sprintf('Unsupported Ozon Search Promo report type "%s".', $reportType)),
        };
    }

    private function nextSearchPromoStatisticsCursor(string $reportType, int $batchOffset, int $campaignCount): ?string
    {
        if (self::SEARCH_PROMO_REPORT_PRODUCTS === $reportType) {
            return $this->encodeCursor([
                'batchOffset' => $batchOffset,
                'reportType' => self::SEARCH_PROMO_REPORT_ORDERS,
                'state' => 'request',
            ]);
        }

        $nextBatchOffset = $batchOffset + self::CAMPAIGN_BATCH_SIZE;
        if ($nextBatchOffset >= $campaignCount) {
            return null;
        }

        return $this->encodeCursor([
            'batchOffset' => $nextBatchOffset,
            'reportType' => self::SEARCH_PROMO_REPORT_PRODUCTS,
            'state' => 'request',
        ]);
    }

    /**
     * @param list<string> $campaignIds
     */
    private function campaignBatchHash(array $campaignIds): string
    {
        return substr(hash('sha256', implode(',', $campaignIds)), 0, 16);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $metadata
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
