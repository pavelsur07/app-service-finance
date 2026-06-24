<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

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
use Ramsey\Uuid\Uuid;

final class FakeConnector implements SourceConnectorInterface
{
    public const RESOURCE_TYPE = 'fake_sales';

    /**
     * @var list<PullRequest>
     */
    private array $pullRequests = [];

    /**
     * @var list<array{externalId: string, nextCursorValue: ?string, hasMore: bool, rowExternalId: string, normalizeRawRecords: bool, continuationDelaySeconds: ?int}|\Throwable>
     */
    private array $queuedPullResults = [];

    private ?\Throwable $nextPullFailure = null;

    public function reset(): void
    {
        $this->pullRequests = [];
        $this->queuedPullResults = [];
        $this->nextPullFailure = null;
    }

    /**
     * Make the next pull() throw the given exception exactly once, then behave normally.
     */
    public function failNextPullWith(\Throwable $exception): void
    {
        $this->nextPullFailure = $exception;
    }

    public function enqueuePullResult(
        string $externalId,
        ?string $nextCursorValue,
        bool $hasMore,
        string $rowExternalId = 'fake-sale-1',
        bool $normalizeRawRecords = true,
        ?int $continuationDelaySeconds = null,
    ): void {
        $this->queuedPullResults[] = [
            'externalId' => $externalId,
            'nextCursorValue' => $nextCursorValue,
            'hasMore' => $hasMore,
            'rowExternalId' => $rowExternalId,
            'normalizeRawRecords' => $normalizeRawRecords,
            'continuationDelaySeconds' => $continuationDelaySeconds,
        ];
    }

    public function enqueuePullFailure(\Throwable $exception): void
    {
        $this->queuedPullResults[] = $exception;
    }

    /**
     * @return list<PullRequest>
     */
    public function pullRequests(): array
    {
        return $this->pullRequests;
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
                externalId: 'fake-shop',
                name: 'Fake shop',
                currency: 'RUB',
                metadata: ['connectionRef' => $connectionRef],
            ),
        ];
    }

    public function pull(PullRequest $request): PullResult
    {
        $this->pullRequests[] = $request;

        if (null !== $this->nextPullFailure) {
            $failure = $this->nextPullFailure;
            $this->nextPullFailure = null;

            throw $failure;
        }

        $result = array_shift($this->queuedPullResults);
        if ($result instanceof \Throwable) {
            throw $result;
        }

        $result ??= [
            'externalId' => sprintf('fake-report-%s', $request->syncJobId),
            'nextCursorValue' => 'cursor-after-fake-sale-1',
            'hasMore' => false,
            'rowExternalId' => 'fake-sale-1',
            'normalizeRawRecords' => true,
            'continuationDelaySeconds' => null,
        ];
        $operationGroupId = Uuid::uuid7()->toString();

        return new PullResult(
            rawBatch: new RawBatch(
                companyId: $request->companyId,
                connectionRef: $request->connectionRef,
                shopRef: $request->shopRef,
                source: $this->source(),
                resourceType: $request->resourceType,
                externalId: $result['externalId'],
                syncJobId: $request->syncJobId,
                fetchedAt: new \DateTimeImmutable('2026-06-18 10:00:00'),
                rows: [[
                    'externalId' => $result['rowExternalId'],
                    'externalUpdatedAt' => '2026-06-18T10:00:00+00:00',
                    'operationGroupId' => $operationGroupId,
                    'amountMinor' => 12345,
                    'controlAmountMinor' => 12345,
                    'currency' => 'RUB',
                    'occurredAt' => '2026-06-18T09:30:00+00:00',
                    'orderRef' => 'order-1',
                    'counterpartyExternalKey' => 'buyer-1',
                    'counterpartyName' => 'Fake Buyer',
                ]],
            ),
            nextCursorValue: $result['nextCursorValue'],
            hasMore: $result['hasMore'],
            normalizeRawRecords: $result['normalizeRawRecords'],
            continuationDelaySeconds: $result['continuationDelaySeconds'],
        );
    }

    public function push(PushRequest $request): PushResult
    {
        throw new UnsupportedCapabilityException('Fake connector does not support push operations.');
    }
}
