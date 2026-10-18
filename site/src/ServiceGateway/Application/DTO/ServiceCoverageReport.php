<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

/**
 * Ответ GET /internal/coverage, контракт services/_contracts/service-coverage/v1.
 */
final readonly class ServiceCoverageReport
{
    /**
     * @param list<ServiceCoverageReportRow> $rows
     */
    public function __construct(
        public string $service,
        public \DateTimeImmutable $generatedAt,
        public \DateTimeImmutable $periodFrom,
        public \DateTimeImmutable $periodTo,
        public int $cabinetsTotal,
        public array $rows,
    ) {
    }
}
