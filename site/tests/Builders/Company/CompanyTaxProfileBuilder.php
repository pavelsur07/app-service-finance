<?php

declare(strict_types=1);

namespace App\Tests\Builders\Company;

use App\Company\Entity\CompanyTaxProfile;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;

final class CompanyTaxProfileBuilder
{
    public const DEFAULT_ID = '01930000-0000-7000-8000-00000000a001';

    private string $id = self::DEFAULT_ID;
    private string $companyId = CompanyBuilder::DEFAULT_COMPANY_ID;
    private TaxRegime $taxRegime = TaxRegime::USN;
    private VatMode $vatMode = VatMode::EXEMPT;
    private ?\DateTimeImmutable $validFrom = null;

    public static function aProfile(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function forCompany(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function with(TaxRegime $taxRegime, VatMode $vatMode): self
    {
        $clone = clone $this;
        $clone->taxRegime = $taxRegime;
        $clone->vatMode = $vatMode;

        return $clone;
    }

    public function validFrom(?string $date): self
    {
        $clone = clone $this;
        $clone->validFrom = null === $date ? null : new \DateTimeImmutable($date);

        return $clone;
    }

    public function build(): CompanyTaxProfile
    {
        return new CompanyTaxProfile(
            $this->companyId,
            $this->taxRegime,
            $this->vatMode,
            $this->validFrom,
            new \DateTimeImmutable('2026-11-01 10:00:00'),
            $this->id,
        );
    }
}
