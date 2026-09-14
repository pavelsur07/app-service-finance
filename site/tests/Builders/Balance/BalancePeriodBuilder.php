<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalancePeriod;

final class BalancePeriodBuilder
{
    private string $companyId = '22222222-2222-4222-8222-222222222222';
    private string $changedBy = '33333333-3333-4333-8333-333333333333';

    public static function aBalancePeriod(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withChangedBy(string $changedBy): self
    {
        $clone = clone $this;
        $clone->changedBy = $changedBy;

        return $clone;
    }

    public function build(): BalancePeriod
    {
        return new BalancePeriod($this->companyId, new \DateTimeImmutable('2026-09-01'), $this->changedBy);
    }
}
