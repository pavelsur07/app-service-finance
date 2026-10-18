<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

/**
 * Число кабинетов по статусам задачи загрузки (`by_status` контракта v1).
 */
final readonly class ServiceCoverageStatusCounts
{
    public function __construct(
        public int $done,
        public int $running,
        public int $pending,
        public int $notReady,
        public int $failedRetryable,
        public int $failedPermanent,
    ) {
    }

    public function total(): int
    {
        return $this->done + $this->running + $this->pending + $this->notReady + $this->failedRetryable + $this->failedPermanent;
    }
}
