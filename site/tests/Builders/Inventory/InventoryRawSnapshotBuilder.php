<?php

declare(strict_types=1);

namespace App\Tests\Builders\Inventory;

use App\Inventory\Entity\InventoryRawSnapshot;
use App\Marketplace\Enum\MarketplaceType;

final class InventoryRawSnapshotBuilder
{
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_SNAPSHOT_SESSION_ID = '22222222-2222-2222-2222-222222222222';
    public const DEFAULT_CORRELATION_ID = '33333333-3333-7333-8333-333333333333';

    private string $companyId = self::DEFAULT_COMPANY_ID;
    private string $snapshotSessionId = self::DEFAULT_SNAPSHOT_SESSION_ID;
    private MarketplaceType $source = MarketplaceType::WILDBERRIES;
    private string $endpoint = '/api/v1/stocks';

    /** @var array<string, mixed> */
    private array $requestParams = ['limit' => 100, 'cursor' => null];

    private int $responseStatus = 200;

    /** @var array<string, mixed> */
    private array $responseBody = ['items' => [['sku' => 'ABC-123', 'qty' => 10]]];

    private \DateTimeImmutable $fetchedAt;
    private int $fetchDurationMs = 120;
    private string $correlationId = self::DEFAULT_CORRELATION_ID;
    private ?int $pageNumber = 1;

    private function __construct()
    {
        $this->fetchedAt = new \DateTimeImmutable('2026-04-20T10:00:00+00:00');
    }

    public static function aRawSnapshot(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withSnapshotSessionId(string $snapshotSessionId): self
    {
        $clone = clone $this;
        $clone->snapshotSessionId = $snapshotSessionId;

        return $clone;
    }

    public function withSource(MarketplaceType $source): self
    {
        $clone = clone $this;
        $clone->source = $source;

        return $clone;
    }

    public function withEndpoint(string $endpoint): self
    {
        $clone = clone $this;
        $clone->endpoint = $endpoint;

        return $clone;
    }

    /**
     * @param array<string, mixed> $requestParams
     */
    public function withRequestParams(array $requestParams): self
    {
        $clone = clone $this;
        $clone->requestParams = $requestParams;

        return $clone;
    }

    public function withResponseStatus(int $responseStatus): self
    {
        $clone = clone $this;
        $clone->responseStatus = $responseStatus;

        return $clone;
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    public function withResponseBody(array $responseBody): self
    {
        $clone = clone $this;
        $clone->responseBody = $responseBody;

        return $clone;
    }

    public function withFetchedAt(\DateTimeImmutable $fetchedAt): self
    {
        $clone = clone $this;
        $clone->fetchedAt = $fetchedAt;

        return $clone;
    }

    public function withFetchDurationMs(int $fetchDurationMs): self
    {
        $clone = clone $this;
        $clone->fetchDurationMs = $fetchDurationMs;

        return $clone;
    }

    public function withCorrelationId(string $correlationId): self
    {
        $clone = clone $this;
        $clone->correlationId = $correlationId;

        return $clone;
    }

    public function withPageNumber(?int $pageNumber): self
    {
        $clone = clone $this;
        $clone->pageNumber = $pageNumber;

        return $clone;
    }

    public function build(): InventoryRawSnapshot
    {
        return new InventoryRawSnapshot(
            companyId: $this->companyId,
            snapshotSessionId: $this->snapshotSessionId,
            source: $this->source,
            sourceEndpoint: $this->endpoint,
            requestParams: $this->requestParams,
            responseStatus: $this->responseStatus,
            responseBody: $this->responseBody,
            fetchedAt: $this->fetchedAt,
            fetchDurationMs: $this->fetchDurationMs,
            correlationId: $this->correlationId,
            pageNumber: $this->pageNumber,
        );
    }
}
