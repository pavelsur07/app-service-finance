<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\BillingPeriod;
use App\Billing\Repository\PlanRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[ORM\Table(name: 'billing_plan')]
#[ORM\UniqueConstraint(name: 'uniq_billing_plan_code', columns: ['code'])]
#[ORM\Index(name: 'idx_billing_plan_is_active', columns: ['is_active'])]
class Plan
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string')]
    private string $code;

    #[ORM\Column(type: 'string')]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $priceAmount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $priceCurrency;

    #[ORM\Column(type: 'string', enumType: BillingPeriod::class)]
    private BillingPeriod $billingPeriod;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $code,
        string $name,
        int $priceAmount,
        string $priceCurrency,
        BillingPeriod $billingPeriod,
        bool $isActive,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
        $this->priceAmount = $priceAmount;
        $this->priceCurrency = $priceCurrency;
        $this->billingPeriod = $billingPeriod;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPriceAmount(): int
    {
        return $this->priceAmount;
    }

    public function getPriceCurrency(): string
    {
        return $this->priceCurrency;
    }

    public function getBillingPeriod(): BillingPeriod
    {
        return $this->billingPeriod;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
