<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalanceAccount;

final class BalanceAccountBuilder
{
    private string $companyId = '22222222-2222-4222-8222-222222222222';
    private string $articleId = '33333333-3333-4333-8333-333333333333';
    private string $code = 'BANK';
    private string $name = 'Основной счет';
    private bool $allowNegative = false;

    public static function aBalanceAccount(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withArticleId(string $articleId): self
    {
        $clone = clone $this;
        $clone->articleId = $articleId;

        return $clone;
    }

    public function withCode(string $code): self
    {
        $clone = clone $this;
        $clone->code = $code;

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withAllowNegative(bool $allowNegative): self
    {
        $clone = clone $this;
        $clone->allowNegative = $allowNegative;

        return $clone;
    }

    public function build(): BalanceAccount
    {
        return new BalanceAccount($this->companyId, $this->articleId, $this->code, $this->name, $this->allowNegative);
    }
}
