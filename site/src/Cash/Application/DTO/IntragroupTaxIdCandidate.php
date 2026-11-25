<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class IntragroupTaxIdCandidate
{
    public function __construct(
        public string $companyId,
        public string $counterpartyId,
        public string $name,
        public string $peerCompanyId,
        public string $inn,
        public int $operationCount,
    ) {
    }
}
