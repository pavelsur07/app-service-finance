<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use Ramsey\Uuid\Uuid;

final class CashTransactionBuilder
{
    private string $id;
    private Company $company;
    private MoneyAccount $moneyAccount;
    private CashDirection $direction;
    private string $amount;
    private string $currency;
    private \DateTimeImmutable $occurredAt;
    private bool $isTransfer;
    private string $allocatedAmount;
    private ?CashflowCategory $cashflowCategory;

    private function __construct()
    {
        $this->id = Uuid::uuid4()->toString();
        $this->company = CompanyBuilder::aCompany()->build();
        $this->direction = CashDirection::OUTFLOW;
        $this->amount = '1000.00';
        $this->currency = 'RUB';
        $this->occurredAt = new \DateTimeImmutable('2024-01-15');
        $this->isTransfer = false;
        $this->allocatedAmount = '0.00';
        $this->cashflowCategory = null;
        $this->moneyAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($this->company)
            ->build();
    }

    public static function aCashTransaction(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function forCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withMoneyAccount(MoneyAccount $account): self
    {
        $clone = clone $this;
        $clone->moneyAccount = $account;

        return $clone;
    }

    public function withAmount(string $amount): self
    {
        $clone = clone $this;
        $clone->amount = $amount;

        return $clone;
    }

    public function withAllocatedAmount(string $allocatedAmount): self
    {
        $clone = clone $this;
        $clone->allocatedAmount = $allocatedAmount;

        return $clone;
    }

    public function withDirection(CashDirection $direction): self
    {
        $clone = clone $this;
        $clone->direction = $direction;

        return $clone;
    }

    public function withCashflowCategory(?CashflowCategory $category): self
    {
        $clone = clone $this;
        $clone->cashflowCategory = $category;

        return $clone;
    }

    public function asTransfer(): self
    {
        $clone = clone $this;
        $clone->isTransfer = true;

        return $clone;
    }

    public function build(): CashTransaction
    {
        $tx = new CashTransaction(
            $this->id,
            $this->company,
            $this->moneyAccount,
            $this->direction,
            $this->amount,
            $this->currency,
            $this->occurredAt,
        );

        $tx->setIsTransfer($this->isTransfer);
        $tx->setCashflowCategory($this->cashflowCategory);

        if ($this->allocatedAmount !== '0.00') {
            $prop = new \ReflectionProperty(CashTransaction::class, 'allocatedAmount');
            $prop->setAccessible(true);
            $prop->setValue($tx, $this->allocatedAmount);
        }

        return $tx;
    }
}
