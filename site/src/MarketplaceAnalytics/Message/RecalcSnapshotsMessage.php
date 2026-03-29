<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Message;

final readonly class RecalcSnapshotsMessage
{
    public function __construct(
        public string $companyId,
        public string $dateFrom,
        public string $dateTo,
    ) {}
}
