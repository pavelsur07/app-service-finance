<?php

namespace App\Company\Application\Command;

class CounterpartyUpdateScoringCommand
{
    public function __construct(
        public string $companyId,
        public string $counterpartyId,
        public ?int $averageDelayDays,
        public int $reliabilityScore
    )
    {
    }
}
