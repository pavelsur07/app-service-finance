<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;

final readonly class DefaultCostMappingRule
{
    public function __construct(
        private MarketplaceType $marketplace,
        private string $costCode,
        private string $plCode,
        private bool $includeInPl,
        private bool $isNegative,
        private string $confidence,
        private ?string $note,
    ) {}

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getCostCode(): string
    {
        return $this->costCode;
    }

    public function getPlCode(): string
    {
        return $this->plCode;
    }

    public function isIncludeInPl(): bool
    {
        return $this->includeInPl;
    }

    public function isNegative(): bool
    {
        return $this->isNegative;
    }

    public function getConfidence(): string
    {
        return $this->confidence;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
