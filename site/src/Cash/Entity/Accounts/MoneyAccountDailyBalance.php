<?php

namespace App\Cash\Entity\Accounts;

use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: \App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository::class)]
#[ORM\Table(name: 'money_account_daily_balance')]
#[ORM\UniqueConstraint(name: 'uniq_company_account_date', columns: ['company_id', 'money_account_id', 'date'])]
#[ORM\Index(name: 'idx_company_date', columns: ['company_id', 'date'])]
#[ORM\Index(name: 'idx_account_date', columns: ['money_account_id', 'date'])]
class MoneyAccountDailyBalance
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: MoneyAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private MoneyAccount $moneyAccount;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $openingBalance;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $inflow;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $outflow;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $closingBalance;

    #[ORM\Column(length: 3)]
    private string $currency;

    public function __construct(string $id, Company $company, MoneyAccount $account, \DateTimeImmutable $date, string $opening, string $inflow, string $outflow, string $closing, string $currency)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->moneyAccount = $account;
        $this->date = $date;
        $this->openingBalance = $opening;
        $this->inflow = $inflow;
        $this->outflow = $outflow;
        $this->closingBalance = $closing;
        $this->currency = strtoupper($currency);
    }

    public function getMoneyAccount(): MoneyAccount
    {
        return $this->moneyAccount;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getOpeningBalance(): string
    {
        return $this->openingBalance;
    }

    public function getInflow(): string
    {
        return $this->inflow;
    }

    public function getOutflow(): string
    {
        return $this->outflow;
    }

    public function getClosingBalance(): string
    {
        return $this->closingBalance;
    }

    public function setOpeningBalance(string $o): self
    {
        $this->openingBalance = $o;

        return $this;
    }

    public function setInflow(string $i): self
    {
        $this->inflow = $i;

        return $this;
    }

    public function setOutflow(string $o): self
    {
        $this->outflow = $o;

        return $this;
    }

    public function setClosingBalance(string $c): self
    {
        $this->closingBalance = $c;

        return $this;
    }
}
