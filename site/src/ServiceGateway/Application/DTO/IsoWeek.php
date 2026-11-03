<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class IsoWeek
{
    /**
     * @param string $key YYYY-Www
     * @param int $daysInMonth дней недели внутри месяца (1…7)
     */
    public function __construct(
        public string $key,
        public \DateTimeImmutable $monday,
        public \DateTimeImmutable $sunday,
        public int $daysInMonth,
    ) {
    }
}
