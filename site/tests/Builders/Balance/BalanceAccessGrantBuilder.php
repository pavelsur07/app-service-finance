<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalanceAccessGrant;

final class BalanceAccessGrantBuilder
{
    private string $companyId = '22222222-2222-4222-8222-222222222222';
    private string $userId = '33333333-3333-4333-8333-333333333333';

    private bool $canReopenPeriods = false;

    public static function aBalanceAccessGrant(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withUserId(string $userId): self
    {
        $clone = clone $this;
        $clone->userId = $userId;

        return $clone;
    }

    public function withReopenPeriods(bool $allowed = true): self
    {
        $clone = clone $this;
        $clone->canReopenPeriods = $allowed;

        return $clone;
    }

    public function build(): BalanceAccessGrant
    {
        return new BalanceAccessGrant($this->companyId, $this->userId, canReopenPeriods: $this->canReopenPeriods);
    }
}
