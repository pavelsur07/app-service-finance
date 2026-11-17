<?php

declare(strict_types=1);

namespace App\Tests\Builders\Company;

use App\Company\Entity\ConsolidationMembership;

final class ConsolidationMembershipBuilder
{
    private string $companyId = CompanyBuilder::DEFAULT_COMPANY_ID;
    private string $headCompanyId = CompanyBuilder::DEFAULT_COMPANY_ID;

    public static function aMembership(): self
    {
        return new self();
    }

    public function forCompany(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withHead(string $headCompanyId): self
    {
        $clone = clone $this;
        $clone->headCompanyId = $headCompanyId;

        return $clone;
    }

    public function build(): ConsolidationMembership
    {
        return new ConsolidationMembership($this->companyId, $this->headCompanyId, new \DateTimeImmutable('2026-11-17 12:00:00'));
    }
}
