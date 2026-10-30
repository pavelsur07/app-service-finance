<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\DefaultSaleMappingPreviewStatus;
use App\Marketplace\Enum\MarketplaceType;

final readonly class DefaultSaleMappingPreviewResult
{
    /** @param list<DefaultSaleMappingPreviewItem> $items */
    public function __construct(
        private MarketplaceType $marketplace,
        private array $items,
    ) {
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    /** @return list<DefaultSaleMappingPreviewItem> */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotal(): int
    {
        return count($this->items);
    }

    public function getCountByStatus(DefaultSaleMappingPreviewStatus $status): int
    {
        return count(array_filter(
            $this->items,
            static fn (DefaultSaleMappingPreviewItem $item): bool => $item->getStatus() === $status,
        ));
    }

    /** @return array<string, int> */
    public function getSummary(): array
    {
        $summary = [];
        foreach (DefaultSaleMappingPreviewStatus::cases() as $status) {
            $summary[$status->value] = $this->getCountByStatus($status);
        }

        return $summary;
    }

    public function hasBlockingIssues(): bool
    {
        return $this->getCountByStatus(DefaultSaleMappingPreviewStatus::MISSING_PL_CATEGORY) > 0
            || $this->getCountByStatus(DefaultSaleMappingPreviewStatus::INVALID_TARGET_CATEGORY) > 0;
    }
}
