<?php

namespace App\Cash\Entity\PaymentPlan;

use App\Cash\Repository\PaymentPlan\PaymentRecurrenceRuleRepository;
use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: PaymentRecurrenceRuleRepository::class)]
#[ORM\Table(name: 'payment_recurrence_rule')]
#[ORM\Index(name: 'idx_payment_recurrence_company_active', columns: ['company_id', 'active'])]
class PaymentRecurrenceRule
{
    public const FREQUENCY_WEEKLY = 'WEEKLY';
    public const FREQUENCY_MONTHLY = 'MONTHLY';
    public const FREQUENCY_QUARTERLY = 'QUARTERLY';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\Column(length: 16)]
    private string $frequency;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $interval = 1;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $byDay = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dayOfMonth = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $until = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    public function __construct(string $id, Company $company, string $frequency)
    {
        Assert::uuid($id);
        Assert::oneOf($frequency, [
            self::FREQUENCY_WEEKLY,
            self::FREQUENCY_MONTHLY,
            self::FREQUENCY_QUARTERLY,
        ]);

        $this->id = $id;
        $this->company = $company;
        $this->frequency = $frequency;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getFrequency(): string
    {
        return $this->frequency;
    }

    public function setFrequency(string $frequency): self
    {
        Assert::oneOf($frequency, [
            self::FREQUENCY_WEEKLY,
            self::FREQUENCY_MONTHLY,
            self::FREQUENCY_QUARTERLY,
        ]);
        $this->frequency = $frequency;

        return $this;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }

    public function setInterval(int $interval): self
    {
        $this->interval = max(1, $interval);

        return $this;
    }

    public function getByDay(): ?string
    {
        return $this->byDay;
    }

    public function setByDay(?string $byDay): self
    {
        $this->byDay = $byDay;

        return $this;
    }

    public function getDayOfMonth(): ?int
    {
        return $this->dayOfMonth;
    }

    public function setDayOfMonth(?int $dayOfMonth): self
    {
        $this->dayOfMonth = $dayOfMonth;

        return $this;
    }

    public function getUntil(): ?\DateTimeImmutable
    {
        return $this->until;
    }

    public function setUntil(?\DateTimeImmutable $until): self
    {
        $this->until = $until;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }
}
