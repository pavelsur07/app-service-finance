<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

final readonly class SyncConnectionCommand
{
    public function __construct(
        public string $companyId,
        public string $connectionId,
        public \DateTimeImmutable $fromDate,
        public \DateTimeImmutable $toDate,
    ) {
    }
}
