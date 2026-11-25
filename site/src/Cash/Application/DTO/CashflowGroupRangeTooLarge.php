<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/** Диапазон прошёл проверку доступа, но превышает предел даже при годовом шаге. */
final readonly class CashflowGroupRangeTooLarge
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $requestedGroup,
    ) {
    }
}
