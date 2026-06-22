<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Wildberries;

interface WbFinanceReportClientInterface
{
    public function fetchDetailedDayPage(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $date,
        int $rrdId,
        int $limit = 100000,
    ): WbFinanceReportPage;
}
