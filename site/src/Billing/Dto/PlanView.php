<?php

declare(strict_types=1);

namespace App\Billing\Dto;

use App\Billing\Enum\BillingPeriod;

final class PlanView
{
    public function __construct(
        private readonly string $id,
        private readonly string $code,
        private readonly string $name,
        private readonly int $priceAmount,
        private readonly string $priceCurrency,
        private readonly BillingPeriod $billingPeriod,
        private readonly bool $isActive,
        private readonly \DateTimeImmutable $createdAt,
    ) {
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
