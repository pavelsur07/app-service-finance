<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class ServiceCoverageSnapshotView
{
    public function __construct(
        public \DateTimeImmutable $generatedAt,
        public \DateTimeImmutable $refreshedAt,
        public \DateTimeImmutable $periodFrom,
        public \DateTimeImmutable $periodTo,
        public int $cabinetsTotal,
    ) {
    }
}
