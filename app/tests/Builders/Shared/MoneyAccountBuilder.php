<?php

declare(strict_types=1);

namespace App\Tests\Builders\Shared;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Entity\Company;
use App\Enum\MoneyAccountType;

final class MoneyAccountBuilder
{
    public const DEFAULT_ACCOUNT_ID = '33333333-3333-3333-3333-333333333333';
    public const DEFAULT_ACCOUNT_NAME = 'Account 1';
    public const DEFAULT_CURRENCY = 'RUB';
    public const DEFAULT_TYPE = MoneyAccountType::BANK;
    public const DEFAULT_DATE = '2024-01-01';
    public const DEFAULT_DATE_TIME = '2024-01-01 00:00:00+00:00';

    private string $id;
    private Company $company;
    private MoneyAccountType $type;
    private string $name;
    private string $currency;

    private function __construct()
    {
        $this->id = self::DEFAULT_ACCOUNT_ID;
        $this->company = CompanyBuilder::aCompany()->build();
        $this->type = self::DEFAULT_TYPE;
        $this->name = self::DEFAULT_ACCOUNT_NAME;
        $this->currency = self::DEFAULT_CURRENCY;
    }

    public static function aMoneyAccount(): self
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
        $clone->name = sprintf('Account %d', $index);

        return $clone;
    }

    public function forCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withType(MoneyAccountType $type): self
    {
        $clone = clone $this;
        $clone->type = $type;

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withCurrency(string $currency): self
    {
        $clone = clone $this;
        $clone->currency = $currency;

        return $clone;
    }

    public function build(): MoneyAccount
    {
        $moneyAccount = new MoneyAccount(
            $this->id,
            $this->company,
            $this->type,
            $this->name,
            $this->currency,
        );

        $moneyAccount->setOpeningBalanceDate(new \DateTimeImmutable(self::DEFAULT_DATE));
        $moneyAccount->setUpdatedAt(new \DateTimeImmutable(self::DEFAULT_DATE_TIME));

        $createdAtProperty = new \ReflectionProperty(MoneyAccount::class, 'createdAt');
        $createdAtProperty->setAccessible(true);
        $createdAtProperty->setValue($moneyAccount, new \DateTimeImmutable(self::DEFAULT_DATE_TIME));

        return $moneyAccount;
    }
}
