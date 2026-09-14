<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalanceAccountState;

final class BalanceAccountStateBuilder
{
    private string $companyId = '22222222-2222-4222-8222-222222222222';
    private string $accountId = '33333333-3333-4333-8333-333333333333';

    public static function aBalanceAccountState(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withAccountId(string $accountId): self
    {
        $clone = clone $this;
        $clone->accountId = $accountId;

        return $clone;
    }

    public function build(): BalanceAccountState
    {
        return new BalanceAccountState($this->companyId, $this->accountId);
    }
}
