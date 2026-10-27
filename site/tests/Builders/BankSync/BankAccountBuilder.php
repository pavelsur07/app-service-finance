<?php

declare(strict_types=1);

namespace App\Tests\Builders\BankSync;

use App\BankSync\Entity\BankAccount;
use Webmozart\Assert\Assert;

final class BankAccountBuilder
{
    public const DEFAULT_ID = '55555555-5555-7555-8555-555555555555';
    public const DEFAULT_ACCOUNT_NUMBER = '40702810900000000001';

    private string $id = self::DEFAULT_ID;
    private string $companyId = BankConnectionBuilder::DEFAULT_COMPANY_ID;
    private string $connectionId = BankConnectionBuilder::DEFAULT_ID;
    private string $accountNumber = self::DEFAULT_ACCOUNT_NUMBER;
    private string $currency = 'RUB';
    private bool $enabled = true;
    private bool $present = true;
    private ?string $moneyAccountId = null;
    private string $description = 'Расчётный счёт';

    private function __construct()
    {
    }

    public static function anAccount(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        Assert::uuid($id);
        $copy = clone $this;
        $copy->id = $id;

        return $copy;
    }

    public function withIndex(int $index): self
    {
        Assert::range($index, 0, 999999999999);

        return $this->withId(sprintf('55555555-5555-7555-8555-%012d', $index));
    }

    public function forConnection(string $companyId, string $connectionId): self
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        $copy = clone $this;
        $copy->companyId = $companyId;
        $copy->connectionId = $connectionId;

        return $copy;
    }

    public function withAccountNumber(string $accountNumber): self
    {
        $copy = clone $this;
        $copy->accountNumber = $accountNumber;

        return $copy;
    }

    public function withCurrency(string $currency): self
    {
        $copy = clone $this;
        $copy->currency = $currency;

        return $copy;
    }

    public function disabledInBank(): self
    {
        $copy = clone $this;
        $copy->enabled = false;

        return $copy;
    }

    public function missingFromBank(): self
    {
        $copy = clone $this;
        $copy->present = false;

        return $copy;
    }

    public function linkedTo(string $moneyAccountId): self
    {
        Assert::uuid($moneyAccountId);
        $copy = clone $this;
        $copy->moneyAccountId = $moneyAccountId;

        return $copy;
    }

    public function build(): BankAccount
    {
        $seenAt = new \DateTimeImmutable('2026-10-27 08:00:00');
        $account = new BankAccount($this->companyId, $this->connectionId, $this->accountNumber, $this->currency, $seenAt, $this->id);
        $account->observeInBank($this->currency, $this->enabled, '044525593', $this->description, 'acc-'.substr($this->accountNumber, -4), $seenAt);
        if (!$this->present) {
            $account->markMissingFromBank();
        }
        if (null !== $this->moneyAccountId) {
            $account->linkTo($this->moneyAccountId);
        }

        return $account;
    }
}
