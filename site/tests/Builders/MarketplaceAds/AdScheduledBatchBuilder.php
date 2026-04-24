<?php

declare(strict_types=1);

namespace App\Tests\Builders\MarketplaceAds;

use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Enum\AdScheduledBatchState;

final class AdScheduledBatchBuilder
{
    public const DEFAULT_ID = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    public const DEFAULT_JOB_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private string $id = self::DEFAULT_ID;
    private string $jobId = self::DEFAULT_JOB_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    /** @var list<string> */
    private array $campaignIds = ['campaign-1', 'campaign-2'];
    private \DateTimeImmutable $dateFrom;
    private \DateTimeImmutable $dateTo;
    private int $batchIndex = 0;
    private \DateTimeImmutable $scheduledAt;
    private ?AdScheduledBatchState $state = null;
    private ?string $ozonUuid = null;
    private ?string $storagePath = null;
    private ?string $fileHash = null;
    private ?int $fileSize = null;

    private function __construct()
    {
        // Таймстампы в тестах — явно UTC. Entity (Task-11.9a-fix) нормализует
        // входящий DateTimeImmutable в UTC; фиксированный UTC в билдере исключает
        // зависимость от PHP default TZ в сравнениях round-trip'ов через Doctrine.
        $utc = new \DateTimeZone('UTC');
        $this->dateFrom = new \DateTimeImmutable('2026-03-01', $utc);
        $this->dateTo = new \DateTimeImmutable('2026-03-10', $utc);
        $this->scheduledAt = new \DateTimeImmutable('2026-03-01 00:00:00', $utc);
    }

    public static function aBatch(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('bbbbbbbb-bbbb-bbbb-bbbb-%012d', $index);
        $clone->batchIndex = $index;

        return $clone;
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withJobId(string $jobId): self
    {
        $clone = clone $this;
        $clone->jobId = $jobId;

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    /**
     * @param list<string> $campaignIds
     */
    public function withCampaignIds(array $campaignIds): self
    {
        $clone = clone $this;
        $clone->campaignIds = $campaignIds;

        return $clone;
    }

    public function withDateRange(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): self
    {
        $clone = clone $this;
        $clone->dateFrom = $dateFrom;
        $clone->dateTo = $dateTo;

        return $clone;
    }

    public function withScheduledAt(\DateTimeImmutable $scheduledAt): self
    {
        $clone = clone $this;
        $clone->scheduledAt = $scheduledAt;

        return $clone;
    }

    public function withState(AdScheduledBatchState $state): self
    {
        $clone = clone $this;
        $clone->state = $state;

        return $clone;
    }

    public function withStorage(string $path, string $hash, int $size): self
    {
        $clone = clone $this;
        $clone->storagePath = $path;
        $clone->fileHash = $hash;
        $clone->fileSize = $size;

        return $clone;
    }

    public function withOzonUuid(string $ozonUuid): self
    {
        $clone = clone $this;
        $clone->ozonUuid = $ozonUuid;

        return $clone;
    }

    public function build(): AdScheduledBatch
    {
        $batch = new AdScheduledBatch(
            id: $this->id,
            jobId: $this->jobId,
            companyId: $this->companyId,
            campaignIds: $this->campaignIds,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            batchIndex: $this->batchIndex,
            scheduledAt: $this->scheduledAt,
        );

        if (null !== $this->state) {
            $batch->setState($this->state);
            $utc = new \DateTimeZone('UTC');
            if (AdScheduledBatchState::IN_FLIGHT === $this->state || $this->state->isTerminal()) {
                $batch->setStartedAt(new \DateTimeImmutable('now', $utc));
            }
            if ($this->state->isTerminal()) {
                $batch->setFinishedAt(new \DateTimeImmutable('now', $utc));
            }
        }

        if (null !== $this->ozonUuid) {
            $batch->setOzonUuid($this->ozonUuid);
        }

        if (null !== $this->storagePath) {
            $batch->setStoragePath($this->storagePath);
            $batch->setFileHash($this->fileHash);
            $batch->setFileSize($this->fileSize);
        }

        return $batch;
    }
}
