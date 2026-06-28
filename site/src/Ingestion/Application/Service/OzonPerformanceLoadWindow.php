<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

final readonly class OzonPerformanceLoadWindow
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $label,
    ) {
    }
}
