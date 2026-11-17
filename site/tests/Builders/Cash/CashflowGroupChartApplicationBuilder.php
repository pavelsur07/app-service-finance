<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\Transaction\CashflowGroupChartApplication;
use App\Tests\Builders\Company\CompanyBuilder;

final class CashflowGroupChartApplicationBuilder
{
    private string $companyId = CompanyBuilder::DEFAULT_COMPANY_ID;
    private int $createdCount = 1;

    public static function anApplication(): self
    {
        return new self();
    }

    public function forCompany(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withCreated(int $createdCount): self
    {
        $clone = clone $this;
        $clone->createdCount = $createdCount;

        return $clone;
    }

    public function build(): CashflowGroupChartApplication
    {
        return new CashflowGroupChartApplication($this->companyId, 'core', null, 0, $this->createdCount, 0, 0, new \DateTimeImmutable('2026-11-17 12:00:00'));
    }
}
