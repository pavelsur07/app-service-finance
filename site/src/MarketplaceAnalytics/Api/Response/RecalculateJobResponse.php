<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response;

final readonly class RecalculateJobResponse
{
    public function __construct(
        private string $jobId,
        private string $status,
        private string $message,
        private string $marketplace,
        private string $dateFrom,
        private string $dateTo,
    ) {}

    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'status' => $this->status,
            'message' => $this->message,
            'marketplace' => $this->marketplace,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
        ];
    }
}
