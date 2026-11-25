<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashflowGroupPeriodColumn
{
    /** @param array<string, string> $amounts ключ строки эталона => итог группы в рублях */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $label,
        public array $amounts,
        public string $opening,
        public string $closing,
        public string $transitNet,
        public string $unpairedNet,
        public string $withoutUnpaired,
    ) {
    }
}
