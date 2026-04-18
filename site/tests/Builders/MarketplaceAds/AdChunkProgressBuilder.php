<?php

declare(strict_types=1);

namespace App\Tests\Builders\MarketplaceAds;

use App\MarketplaceAds\Entity\AdChunkProgress;

final class AdChunkProgressBuilder
{
    public const DEFAULT_ID = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
    public const DEFAULT_JOB_ID = AdLoadJobBuilder::DEFAULT_ID;

    private string $id = self::DEFAULT_ID;
    private string $jobId = self::DEFAULT_JOB_ID;
    private \DateTimeImmutable $dateFrom;
    private \DateTimeImmutable $dateTo;

    private function __construct()
    {
        $this->dateFrom = new \DateTimeImmutable('2026-03-01');
        $this->dateTo = new \DateTimeImmutable('2026-03-03');
    }

    public static function aProgress(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('cccccccc-cccc-cccc-cccc-%012d', $index);

        return $clone;
    }

    public function withJobId(string $jobId): self
    {
        $clone = clone $this;
        $clone->jobId = $jobId;

        return $clone;
    }

    public function withDateRange(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): self
    {
        $clone = clone $this;
        $clone->dateFrom = $dateFrom;
        $clone->dateTo = $dateTo;

        return $clone;
    }

    public function build(): AdChunkProgress
    {
        $progress = new AdChunkProgress(
            jobId: $this->jobId,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
        );

        // Конструктор генерирует UUID v7, Builder обязан выдавать детерминированный ID.
        $reflection = new \ReflectionProperty(AdChunkProgress::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($progress, $this->id);

        return $progress;
    }
}
