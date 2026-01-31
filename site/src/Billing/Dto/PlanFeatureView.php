<?php

declare(strict_types=1);

namespace App\Billing\Dto;

final class PlanFeatureView
{
    public function __construct(
        private readonly string $featureCode,
        private readonly string $value,
        private readonly ?int $softLimit,
        private readonly ?int $hardLimit,
    ) {
    }

    public function getFeatureCode(): string
    {
        return $this->featureCode;
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
