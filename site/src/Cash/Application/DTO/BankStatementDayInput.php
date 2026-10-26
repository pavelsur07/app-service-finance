<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class BankStatementDayInput
{
    /**
     * @param list<BankStatementLineInput> $lines
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $lines,
    ) {
    }
}
