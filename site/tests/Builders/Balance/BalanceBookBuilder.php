<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalanceBook;

final class BalanceBookBuilder
{
    private string $companyId = '22222222-2222-4222-8222-222222222222';

    public static function aBalanceBook(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function build(): BalanceBook
    {
        return new BalanceBook($this->companyId);
    }
}
