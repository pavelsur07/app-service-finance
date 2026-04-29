<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\DefaultCostMappingConfidence;
use App\Marketplace\Enum\DefaultCostMappingPreviewStatus;
use App\Marketplace\Enum\MarketplaceType;

final readonly class DefaultCostMappingPreviewItem
{
    public function __construct(
        private MarketplaceType $marketplace,
        private string $costCode,
        private ?string $costCategoryId,
        private ?string $costCategoryName,
        private string $plCode,
        private ?string $plCategoryId,
        private ?string $plCategoryName,
        private ?string $existingMappingId,
        private ?string $existingPlCategoryId,
        private ?string $existingPlCategoryName,
        private bool $includeInPl,
        private bool $isNegative,
        private DefaultCostMappingConfidence $confidence,
        private ?string $note,
        private DefaultCostMappingPreviewStatus $status,
        private string $message,
    ) {
    }

    public function getMarketplace(): MarketplaceType { return $this->marketplace; }
    public function getCostCode(): string { return $this->costCode; }
    public function getCostCategoryId(): ?string { return $this->costCategoryId; }
    public function getCostCategoryName(): ?string { return $this->costCategoryName; }
    public function getPlCode(): string { return $this->plCode; }
    public function getPlCategoryId(): ?string { return $this->plCategoryId; }
    public function getPlCategoryName(): ?string { return $this->plCategoryName; }
    public function getExistingMappingId(): ?string { return $this->existingMappingId; }
    public function getExistingPlCategoryId(): ?string { return $this->existingPlCategoryId; }
    public function getExistingPlCategoryName(): ?string { return $this->existingPlCategoryName; }
    public function isIncludeInPl(): bool { return $this->includeInPl; }
    public function isNegative(): bool { return $this->isNegative; }
    public function getConfidence(): DefaultCostMappingConfidence { return $this->confidence; }
    public function getNote(): ?string { return $this->note; }
    public function getStatus(): DefaultCostMappingPreviewStatus { return $this->status; }
    public function getMessage(): string { return $this->message; }
}
