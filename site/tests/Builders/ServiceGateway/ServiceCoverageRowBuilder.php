<?php

declare(strict_types=1);

namespace App\Tests\Builders\ServiceGateway;

use App\ServiceGateway\Entity\ServiceCoverageRow;

final class ServiceCoverageRowBuilder
{
    public const DEFAULT_SERVICE = 'ozon-fetcher';
    public const DEFAULT_RESOURCE = 'ozon.finance.accrual-by-day';

    private string $service = self::DEFAULT_SERVICE;
    private string $resourceType = self::DEFAULT_RESOURCE;
    private string $periodKey = '2026-10-15';
    private int $cabinetsExpected = 4;
    private int $done = 4;
    private int $running = 0;
    private int $pending = 0;
    private int $notReady = 0;
    private int $failedRetryable = 0;
    private int $failedPermanent = 0;
    private ?\DateTimeImmutable $lastSuccessAt = null;

    private function __construct()
    {
    }

    public static function aRow(): self
    {
        return new self();
    }

    public function withService(string $service): self
    {
        $clone = clone $this;
        $clone->service = $service;

        return $clone;
    }

    public function withResourceType(string $resourceType): self
    {
        $clone = clone $this;
        $clone->resourceType = $resourceType;

        return $clone;
    }

    public function withPeriodKey(string $periodKey): self
    {
        $clone = clone $this;
        $clone->periodKey = $periodKey;

        return $clone;
    }

    public function withCounts(int $expected, int $done, int $pending = 0, int $failedPermanent = 0): self
    {
        $clone = clone $this;
        $clone->cabinetsExpected = $expected;
        $clone->done = $done;
        $clone->pending = $pending;
        $clone->failedPermanent = $failedPermanent;

        return $clone;
    }

    public function withLastSuccessAt(?\DateTimeImmutable $lastSuccessAt): self
    {
        $clone = clone $this;
        $clone->lastSuccessAt = $lastSuccessAt;

        return $clone;
    }

    public function build(): ServiceCoverageRow
    {
        return new ServiceCoverageRow(
            service: $this->service,
            resourceType: $this->resourceType,
            periodKey: $this->periodKey,
            cabinetsExpected: $this->cabinetsExpected,
            done: $this->done,
            running: $this->running,
            pending: $this->pending,
            notReady: $this->notReady,
            failedRetryable: $this->failedRetryable,
            failedPermanent: $this->failedPermanent,
            lastSuccessAt: $this->lastSuccessAt,
        );
    }
}
