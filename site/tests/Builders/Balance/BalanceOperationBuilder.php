<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalanceOperation;
use App\Balance\Enum\BalanceOperationKind;

final class BalanceOperationBuilder
{
    private string $companyId = '22222222-2222-4222-8222-222222222222';
    private string $authorId = '33333333-3333-4333-8333-333333333333';
    private string $number = '1';

    public static function aBalanceOperation(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withAuthorId(string $authorId): self
    {
        $clone = clone $this;
        $clone->authorId = $authorId;

        return $clone;
    }

    public function withNumber(string $number): self
    {
        $clone = clone $this;
        $clone->number = $number;

        return $clone;
    }

    public function build(): BalanceOperation
    {
        return new BalanceOperation($this->companyId, $this->number, BalanceOperationKind::OPERATION, new \DateTimeImmutable('2026-09-14'), $this->authorId, 'test-operation', hash('sha256', 'test-operation'));
    }
}
