<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Company\Entity\Company;

final class MoneyFundBuilder
{
    public const DEFAULT_FUND_ID = '33333333-3333-3333-3333-333333333333';
    public const DEFAULT_NAME = 'Test Fund';
    public const DEFAULT_CURRENCY = 'RUB';

    private string $id;
    private ?Company $company = null;
    private string $name;
    private string $currency;

    private function __construct()
    {
        $this->id = self::DEFAULT_FUND_ID;
        $this->name = self::DEFAULT_NAME;
        $this->currency = self::DEFAULT_CURRENCY;
    }

    public static function aFund(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('33333333-3333-3333-3333-%012d', $index);
        $clone->name = sprintf('Fund %d', $index);

        return $clone;
    }

    public function withCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withName(string $name): self
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('MoneyFund name cannot be empty.');
        }

        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withCurrency(string $currency): self
    {
        $currency = strtoupper(trim($currency));
        if (3 !== strlen($currency)) {
            throw new \InvalidArgumentException('Currency must be a 3-letter ISO code.');
        }

        $clone = clone $this;
        $clone->currency = $currency;

        return $clone;
    }

    public function build(): MoneyFund
    {
        if (null === $this->company) {
            throw new \LogicException('MoneyFundBuilder: company must be set via withCompany().');
        }

        return new MoneyFund($this->id, $this->company, $this->name, $this->currency);
    }
}
