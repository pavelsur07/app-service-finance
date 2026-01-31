<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\SubscriptionIntegrationStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Billing\Repository\SubscriptionIntegrationRepository::class)]
#[ORM\Table(name: 'billing_subscription_integration')]
#[ORM\UniqueConstraint(name: 'uniq_billing_subscription_integration_subscription_integration', columns: ['subscription_id', 'integration_id'])]
final class SubscriptionIntegration
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Subscription $subscription;

    #[ORM\ManyToOne(targetEntity: Integration::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Integration $integration;

    #[ORM\Column(type: 'string', enumType: SubscriptionIntegrationStatus::class)]
    private SubscriptionIntegrationStatus $status;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt;

    public function __construct(
        string $id,
        Subscription $subscription,
        Integration $integration,
        ?\DateTimeImmutable $startedAt = null,
    ) {
        $this->id = $id;
        $this->subscription = $subscription;
        $this->integration = $integration;
        $this->status = SubscriptionIntegrationStatus::ACTIVE;
        $this->startedAt = $startedAt ?? new \DateTimeImmutable();
        $this->endedAt = null;
    }

    public function activate(\DateTimeImmutable $at): void
    {
        if ($this->status === SubscriptionIntegrationStatus::ACTIVE) {
            throw new \LogicException('Subscription integration is already active.');
        }

        $this->status = SubscriptionIntegrationStatus::ACTIVE;
        $this->startedAt = $at;
        $this->endedAt = null;
    }

    public function disable(\DateTimeImmutable $at): void
    {
        if ($this->status === SubscriptionIntegrationStatus::DISABLED) {
            throw new \LogicException('Subscription integration is already disabled.');
        }

        $this->status = SubscriptionIntegrationStatus::DISABLED;
        $this->endedAt = $at;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }

    public function getIntegration(): Integration
    {
        return $this->integration;
    }

    public function getStatus(): SubscriptionIntegrationStatus
    {
        return $this->status;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }
}
