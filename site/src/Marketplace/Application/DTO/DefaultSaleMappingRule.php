<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;

final readonly class DefaultSaleMappingRule
{
    public function __construct(
        private MarketplaceType $marketplace,
        private AmountSource $amountSource,
        private string $plCode,
        private bool $isNegative,
        private ?string $description = null,
    ) {
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getAmountSource(): AmountSource
    {
        return $this->amountSource;
    }

    public function getOperationType(): string
    {
        return $this->amountSource->getOperationType();
    }

    public function getPlCode(): string
    {
        return $this->plCode;
    }

    public function isNegative(): bool
    {
        return $this->isNegative;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
