<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Результат проверки готовности ожидаемых дней периода.
 */
final readonly class MonthCloseDayCoverage
{
    /**
     * @param list<MonthCloseDayViolation> $violations
     */
    public function __construct(
        public int $expectedDays,
        public array $violations,
    ) {
    }

    public function isReady(): bool
    {
        return [] === $this->violations;
    }
}
