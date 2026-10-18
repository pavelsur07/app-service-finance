<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class ServiceCoverageReportRow
{
    public function __construct(
        public string $resourceType,
        public string $periodKey,
        public int $cabinetsExpected,
        public ServiceCoverageStatusCounts $byStatus,
        public ?\DateTimeImmutable $lastSuccessAt,
    ) {
    }
}
