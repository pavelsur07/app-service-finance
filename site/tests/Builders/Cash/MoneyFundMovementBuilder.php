<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Cash\Entity\Accounts\MoneyFundMovement;
use App\Company\Entity\Company;

final class MoneyFundMovementBuilder
{
    public const DEFAULT_MOVEMENT_ID = '44444444-4444-4444-4444-444444444444';
    public const DEFAULT_AMOUNT_MINOR = 100;
    public const DEFAULT_OCCURRED_AT = '2024-01-01 00:00:00+00:00';

    private string $id;
    private ?Company $company = null;
    private ?MoneyFund $fund = null;
    private \DateTimeImmutable $occurredAt;
    private int $amountMinor;

    private function __construct()
    {
        $this->id = self::DEFAULT_MOVEMENT_ID;
        $this->occurredAt = new \DateTimeImmutable(self::DEFAULT_OCCURRED_AT);
        $this->amountMinor = self::DEFAULT_AMOUNT_MINOR;
    }

    public static function aMovement(): self
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
        $clone->id = sprintf('44444444-4444-4444-4444-%012d', $index);

        return $clone;
    }

    public function withCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withFund(MoneyFund $fund): self
    {
        $clone = clone $this;
        $clone->fund = $fund;

        return $clone;
    }

    public function withOccurredAt(\DateTimeImmutable $occurredAt): self
    {
        $clone = clone $this;
        $clone->occurredAt = $occurredAt;

        return $clone;
    }

    public function withAmountMinor(int $amountMinor): self
    {
        $clone = clone $this;
        $clone->amountMinor = $amountMinor;

        return $clone;
    }

    public function build(): MoneyFundMovement
    {
        if (null === $this->company) {
            throw new \LogicException('MoneyFundMovementBuilder: company must be set via withCompany().');
        }
        if (null === $this->fund) {
            throw new \LogicException('MoneyFundMovementBuilder: fund must be set via withFund().');
        }

        return new MoneyFundMovement(
            $this->id,
            $this->company,
            $this->fund,
            $this->occurredAt,
            $this->amountMinor,
        );
    }
}
