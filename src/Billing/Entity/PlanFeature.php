<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Billing\Repository\PlanFeatureRepository::class)]
#[ORM\Table(name: 'billing_plan_feature')]
#[ORM\UniqueConstraint(name: 'uniq_billing_plan_feature_plan_feature', columns: ['plan_id', 'feature_id'])]
final class PlanFeature
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    #[ORM\ManyToOne(targetEntity: Feature::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Feature $feature;

    #[ORM\Column(type: 'string')]
    private string $value;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $softLimit;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $hardLimit;

    public function __construct(
        string $id,
        Plan $plan,
        Feature $feature,
        string $value,
        ?int $softLimit,
        ?int $hardLimit,
    ) {
        $this->id = $id;
        $this->plan = $plan;
        $this->feature = $feature;
        $this->value = $value;
        $this->softLimit = $softLimit;
        $this->hardLimit = $hardLimit;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function getFeature(): Feature
    {
        return $this->feature;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getSoftLimit(): ?int
    {
        return $this->softLimit;
    }

    public function getHardLimit(): ?int
    {
        return $this->hardLimit;
    }
}
