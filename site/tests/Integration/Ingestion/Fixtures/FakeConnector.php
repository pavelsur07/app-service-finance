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
     * @var list<array{externalId: string, nextCursorValue: ?string, hasMore: bool, rowExternalId: string}>
     */
    private array $queuedPullResults = [];

    public function reset(): void
    {
        $this->pullRequests = [];
        $this->queuedPullResults = [];
    }

    public function enqueuePullResult(
        string $externalId,
        ?string $nextCursorValue,
        bool $hasMore,
        string $rowExternalId = 'fake-sale-1',
    ): void {
        $this->queuedPullResults[] = [
            'externalId' => $externalId,
            'nextCursorValue' => $nextCursorValue,
            'hasMore' => $hasMore,
            'rowExternalId' => $rowExternalId,
        ];
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
        $result = array_shift($this->queuedPullResults) ?? [
            'externalId' => sprintf('fake-report-%s', $request->syncJobId),
            'nextCursorValue' => 'cursor-after-fake-sale-1',
            'hasMore' => false,
            'rowExternalId' => 'fake-sale-1',
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
        );
    }

    public function push(PushRequest $request): PushResult
    {
        throw new UnsupportedCapabilityException('Fake connector does not support push operations.');
    }
}
