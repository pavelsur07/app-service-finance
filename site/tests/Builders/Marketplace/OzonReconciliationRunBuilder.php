<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\OzonReconciliationRun;
use App\Marketplace\Enum\OzonReconciliationStatus;

final class OzonReconciliationRunBuilder
{
    public const DEFAULT_ID = '55555555-5555-5555-5555-555555555555';
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private \DateTimeImmutable $periodFrom;
    private \DateTimeImmutable $periodTo;
    private OzonReconciliationStatus $status = OzonReconciliationStatus::MATCHED;
    private int $mismatchCount = 0;

    private function __construct()
    {
        $this->periodFrom = new \DateTimeImmutable('2026-06-01');
        $this->periodTo = new \DateTimeImmutable('2026-06-30');
    }

    public static function aRun(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('55555555-5555-5555-5555-%012d', $index);

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function forMonth(int $year, int $month): self
    {
        $clone = clone $this;
        $clone->periodFrom = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $clone->periodTo = $clone->periodFrom->modify('last day of this month');

        return $clone;
    }

    public function asMismatch(int $count = 1): self
    {
        $clone = clone $this;
        $clone->status = OzonReconciliationStatus::MISMATCH;
        $clone->mismatchCount = $count;

        return $clone;
    }

    public function asNoData(): self
    {
        $clone = clone $this;
        $clone->status = OzonReconciliationStatus::NO_DATA;

        return $clone;
    }

    public function build(): OzonReconciliationRun
    {
        $run = new OzonReconciliationRun($this->id, $this->companyId, $this->periodFrom, $this->periodTo);
        $run->recordResult(30, 30, true, 0, $this->status, $this->mismatchCount, new \DateTimeImmutable('2026-07-01 03:00:00'));

        return $run;
    }
}
