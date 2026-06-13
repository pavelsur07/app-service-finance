<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdScheduledBatch;

interface AdScheduledBatchRepositoryInterface
{
    public function findNextPlanned(): ?AdScheduledBatch;

    /**
     * @return list<AdScheduledBatch>
     */
    public function findAllInFlight(): array;

    /**
     * @return array<string, int>
     */
    public function countStatesForJob(string $jobId, string $companyId): array;

    public function abandonNonTerminalBatchesForTerminalJob(string $jobId, string $reason): int;
}
