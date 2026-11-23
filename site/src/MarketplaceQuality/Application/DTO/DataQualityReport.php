<?php

declare(strict_types=1);

namespace App\MarketplaceQuality\Application\DTO;

/** Отчёт R10 «Качество данных» компании за период. */
final readonly class DataQualityReport
{
    /**
     * @param list<CabinetQuality> $cabinets
     * @param list<QualityProblem> $problems новые дни первыми
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $cabinets,
        public array $problems,
    ) {
    }
}
