<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalanceOperationLine;
use App\Balance\Enum\BalanceDirection;

final class BalanceOperationLineBuilder
{
    private string $companyId = '22222222-2222-4222-8222-222222222222';
    private string $operationId = '33333333-3333-4333-8333-333333333333';
    private string $accountId = '33333333-3333-4333-8333-333333333333';
    private string $amount = '100';
    private BalanceDirection $direction = BalanceDirection::INCREASE;

    public static function aBalanceOperationLine(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withOperationId(string $operationId): self
    {
        $clone = clone $this;
        $clone->operationId = $operationId;

        return $clone;
    }

    public function withAccountId(string $accountId): self
    {
        $clone = clone $this;
        $clone->accountId = $accountId;

        return $clone;
    }

    public function withAmount(string $amount): self
    {
        $clone = clone $this;
        $clone->amount = $amount;

        return $clone;
    }

    public function withDirection(BalanceDirection $direction): self
    {
        $clone = clone $this;
        $clone->direction = $direction;

        return $clone;
    }

    public function build(): BalanceOperationLine
    {
        return new BalanceOperationLine($this->companyId, $this->operationId, $this->accountId, $this->direction, $this->amount);
    }
}
