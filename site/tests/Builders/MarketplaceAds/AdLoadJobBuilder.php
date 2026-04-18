<?php

declare(strict_types=1);

namespace App\Tests\Builders\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdLoadJobStatus;

final class AdLoadJobBuilder
{
    public const DEFAULT_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private MarketplaceType $marketplace = MarketplaceType::OZON;
    private \DateTimeImmutable $dateFrom;
    private \DateTimeImmutable $dateTo;
    private AdLoadJobStatus $status = AdLoadJobStatus::PENDING;
    private ?string $failureReason = null;
    private int $loadedDays = 0;
    private int $processedDays = 0;
    private int $failedDays = 0;
    private int $chunksTotal = 0;

    private function __construct()
    {
        $this->dateFrom = new \DateTimeImmutable('2026-03-01');
        $this->dateTo = new \DateTimeImmutable('2026-03-10');
    }

    public static function aJob(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('aaaaaaaa-aaaa-aaaa-aaaa-%012d', $index);

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function withDateRange(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): self
    {
        $clone = clone $this;
        $clone->dateFrom = $dateFrom;
        $clone->dateTo = $dateTo;

        return $clone;
    }

    public function asRunning(): self
    {
        $clone = clone $this;
        $clone->status = AdLoadJobStatus::RUNNING;
        $clone->failureReason = null;

        return $clone;
    }

    public function asCompleted(): self
    {
        $clone = clone $this;
        $clone->status = AdLoadJobStatus::COMPLETED;
        $clone->failureReason = null;

        return $clone;
    }

    public function asFailed(string $reason = 'Тестовая ошибка'): self
    {
        $clone = clone $this;
        $clone->status = AdLoadJobStatus::FAILED;
        $clone->failureReason = $reason;

        return $clone;
    }

    public function withLoaded(int $days): self
    {
        $clone = clone $this;
        $clone->loadedDays = $days;

        return $clone;
    }

    public function withProcessed(int $days): self
    {
        $clone = clone $this;
        $clone->processedDays = $days;

        return $clone;
    }

    public function withFailed(int $days): self
    {
        $clone = clone $this;
        $clone->failedDays = $days;

        return $clone;
    }

    public function withChunksTotal(int $total): self
    {
        $clone = clone $this;
        $clone->chunksTotal = $total;

        return $clone;
    }

    public function build(): AdLoadJob
    {
        $job = new AdLoadJob(
            companyId: $this->companyId,
            marketplace: $this->marketplace,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
        );

        // Конструктор генерирует UUID v7, а Builder обязан выдавать детерминированный ID.
        $this->setProperty($job, 'id', $this->id);

        // Счётчики и статус изменяются только через guard-методы / raw SQL в Repository.
        // Для произвольного состояния в тестах используем Reflection.
        if ($this->loadedDays !== 0) {
            $this->setProperty($job, 'loadedDays', $this->loadedDays);
        }
        if ($this->processedDays !== 0) {
            $this->setProperty($job, 'processedDays', $this->processedDays);
        }
        if ($this->failedDays !== 0) {
            $this->setProperty($job, 'failedDays', $this->failedDays);
        }
        if ($this->chunksTotal !== 0) {
            $this->setProperty($job, 'chunksTotal', $this->chunksTotal);
        }

        if (AdLoadJobStatus::PENDING !== $this->status) {
            $this->setProperty($job, 'status', $this->status);
            if (AdLoadJobStatus::RUNNING === $this->status || AdLoadJobStatus::COMPLETED === $this->status) {
                $this->setProperty($job, 'startedAt', new \DateTimeImmutable());
            }
            if ($this->status->isTerminal()) {
                $this->setProperty($job, 'finishedAt', new \DateTimeImmutable());
            }
            if (AdLoadJobStatus::FAILED === $this->status) {
                $this->setProperty($job, 'failureReason', $this->failureReason);
            }
        }

        return $job;
    }

    private function setProperty(AdLoadJob $job, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty(AdLoadJob::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($job, $value);
    }
}
