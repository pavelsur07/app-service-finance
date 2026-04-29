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

    public function getStatus(): DefaultCostMappingPreviewStatus { return $this->status; }
}
