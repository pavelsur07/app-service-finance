<?php

declare(strict_types=1);

namespace App\Billing\Dto;

final class LimitState
{
    private string $metric;
    private int $used;
    private ?int $softLimit;
    private ?int $hardLimit;
    private ?int $remaining;
    private bool $isSoftExceeded;
    private bool $isHardExceeded;

    public function __construct(
        string $metric,
        int $used,
        ?int $softLimit,
        ?int $hardLimit,
        ?int $remaining,
        bool $isSoftExceeded,
        bool $isHardExceeded,
    ) {
        $this->metric = $metric;
        $this->used = $used;
        $this->softLimit = $softLimit;
        $this->hardLimit = $hardLimit;
        $this->remaining = $remaining;
        $this->isSoftExceeded = $isSoftExceeded;
        $this->isHardExceeded = $isHardExceeded;
    }

    public function getMetric(): string
    {
        return $this->metric;
    }

    public function getUsed(): int
    {
        return $this->used;
    }

    public function getSoftLimit(): ?int
    {
        return $this->softLimit;
    }

    public function getHardLimit(): ?int
    {
        return $this->hardLimit;
    }

    public function getRemaining(): ?int
    {
        return $this->remaining;
    }

    public function isSoftExceeded(): bool
    {
        return $this->isSoftExceeded;
    }

    public function isHardExceeded(): bool
    {
        return $this->isHardExceeded;
    }

    public function canWrite(): bool
    {
        return !$this->isHardExceeded;
    }
}
