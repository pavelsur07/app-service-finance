<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\SubscriptionStatus;
use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Billing\Repository\SubscriptionRepository::class)]
#[ORM\Table(name: 'billing_subscription')]
#[ORM\Index(name: 'idx_billing_subscription_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_billing_subscription_status', columns: ['status'])]
#[ORM\Index(name: 'idx_billing_subscription_current_period_end', columns: ['current_period_end'])]
final class Subscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Plan $plan;

    #[ORM\Column(type: 'string', enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $currentPeriodStart;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column(type: 'boolean')]
    private bool $cancelAtPeriodEnd;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        Company $company,
        Plan $plan,
        SubscriptionStatus $status,
        ?\DateTimeImmutable $trialEndsAt,
        \DateTimeImmutable $currentPeriodStart,
        \DateTimeImmutable $currentPeriodEnd,
        bool $cancelAtPeriodEnd,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->company = $company;
        $this->plan = $plan;
        $this->status = $status;
        $this->trialEndsAt = $trialEndsAt;
        $this->currentPeriodStart = $currentPeriodStart;
        $this->currentPeriodEnd = $currentPeriodEnd;
        $this->cancelAtPeriodEnd = $cancelAtPeriodEnd;
        $this->createdAt = $createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function getCurrentPeriodStart(): \DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function getCurrentPeriodEnd(): \DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function isCancelAtPeriodEnd(): bool
    {
        return $this->cancelAtPeriodEnd;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
