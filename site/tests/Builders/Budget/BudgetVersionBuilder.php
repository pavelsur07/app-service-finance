<?php

declare(strict_types=1);

namespace App\Tests\Builders\Budget;

use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetVersionStatus;
use App\Tests\Builders\Company\CompanyBuilder;

final class BudgetVersionBuilder
{
    public const DEFAULT_ID = '0193cccc-0000-7000-8000-0000000b0001';

    private string $id = self::DEFAULT_ID;
    private string $companyId = CompanyBuilder::DEFAULT_COMPANY_ID;
    private int $year = 2027;
    private string $name = 'Базовый';
    private BudgetVersionStatus $status = BudgetVersionStatus::DRAFT;
    private ?string $copiedFromId = null;

    public static function aVersion(): self
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

    public function withYear(int $year): self
    {
        $clone = clone $this;
        $clone->year = $year;

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withStatus(BudgetVersionStatus $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function copiedFrom(string $versionId): self
    {
        $clone = clone $this;
        $clone->copiedFromId = $versionId;

        return $clone;
    }

    public function build(): BudgetVersion
    {
        $now = new \DateTimeImmutable('2026-12-01 10:00:00');
        $version = new BudgetVersion($this->id, $this->companyId, $this->year, $this->name, $now, $this->copiedFromId);
        match ($this->status) {
            BudgetVersionStatus::DRAFT => null,
            BudgetVersionStatus::APPROVED => $version->approve($now),
            BudgetVersionStatus::ARCHIVED => $version->archive($now),
        };

        return $version;
    }
}
