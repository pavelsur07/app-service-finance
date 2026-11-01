<?php

declare(strict_types=1);

namespace App\Company\Facade\DTO;

use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;

final readonly class CompanyTaxProfileDTO
{
    public function __construct(
        public TaxRegime $taxRegime,
        public VatMode $vatMode,
        /** null — начальный профиль («с начала учёта») */
        public ?\DateTimeImmutable $validFrom,
    ) {
    }

    public function label(): string
    {
        return $this->taxRegime->label().', '.$this->vatMode->label();
    }
}
