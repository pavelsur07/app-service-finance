<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: \App\Repository\MoneyFundMovementRepository::class)]
#[ORM\Table(name: '`money_fund_movement`')]
#[ORM\Index(columns: ['company_id', 'fund_id'], name: 'idx_money_fund_movement_company_fund')]
#[ORM\Index(columns: ['company_id', 'occurred_at'], name: 'idx_money_fund_movement_company_occurred_at')]
class MoneyFundMovement
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: MoneyFund::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MoneyFund $fund;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'bigint')]
    private string $amountMinor = '0';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $userId = null;

    public function __construct(
        string $id,
        Company $company,
        MoneyFund $fund,
        \DateTimeImmutable $occurredAt,
        int $amountMinor = 0,
    ) {
        Assert::uuid($id);
        if ($fund->getCompany()->getId() !== $company->getId()) {
            throw new \InvalidArgumentException('Fund and movement company mismatch.');
        }

        $this->id = $id;
        $this->company = $company;
        $this->fund = $fund;
        $this->occurredAt = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
        $this->setAmountMinor($amountMinor);
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getFund(): MoneyFund
    {
        return $this->fund;
    }

    public function setFund(MoneyFund $fund): self
    {
        if ($fund->getCompany()->getId() !== $this->company->getId()) {
            throw new \InvalidArgumentException('Fund and movement company mismatch.');
        }

        $this->fund = $fund;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function getAmountMinor(): int
    {
        return (int) $this->amountMinor;
    }

    public function setAmountMinor(int $amountMinor): self
    {
        $this->amountMinor = (string) $amountMinor;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(?string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }
}
