<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\DefaultCostMappingPreviewStatus;
use App\Marketplace\Enum\MarketplaceType;

final readonly class DefaultCostMappingPreviewResult
{
    /** @param list<DefaultCostMappingPreviewItem> $items */
    public function __construct(
        private MarketplaceType $marketplace,
        private array $items,
    ) {
    }

    public function getMarketplace(): MarketplaceType { return $this->marketplace; }
    /** @return list<DefaultCostMappingPreviewItem> */
    public function getItems(): array { return $this->items; }
    /** @return list<DefaultCostMappingPreviewItem> */
    public function getItemsByStatus(DefaultCostMappingPreviewStatus $status): array
    {
        return array_values(array_filter($this->items, static fn (DefaultCostMappingPreviewItem $item): bool => $item->getStatus() === $status));
    }

    public function getTotal(): int { return count($this->items); }
    public function getCountByStatus(DefaultCostMappingPreviewStatus $status): int { return count($this->getItemsByStatus($status)); }

    /** @return array<string, int> */
    public function getSummary(): array
    {
        $summary = [];
        foreach (DefaultCostMappingPreviewStatus::cases() as $status) {
            $summary[$status->value] = $this->getCountByStatus($status);
        }

        return $summary;
    }

    public function hasBlockingIssues(): bool
    {
        return $this->getCountByStatus(DefaultCostMappingPreviewStatus::MISSING_PL_CATEGORY) > 0
            || $this->getCountByStatus(DefaultCostMappingPreviewStatus::INVALID_TARGET_CATEGORY) > 0;
    }
}
