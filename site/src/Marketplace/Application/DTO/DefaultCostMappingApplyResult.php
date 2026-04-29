<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\DefaultCostMappingPreviewStatus;
use App\Marketplace\Enum\MarketplaceType;

final readonly class DefaultCostMappingApplyResult
{
    /**
     * @param list<string> $createdCostCodes
     * @param list<string> $updatedCostCodes
     * @param list<string> $skippedCostCodes
     * @param list<string> $blockedCostCodes
     */
    public function __construct(
        private MarketplaceType $marketplace,
        private DefaultCostMappingPreviewResult $preview,
        private array $createdCostCodes,
        private array $updatedCostCodes,
        private array $skippedCostCodes,
        private array $blockedCostCodes,
    ) {
    }

    public function getMarketplace(): MarketplaceType { return $this->marketplace; }
    public function getPreview(): DefaultCostMappingPreviewResult { return $this->preview; }
    public function getCreatedCount(): int { return count($this->createdCostCodes); }
    public function getUpdatedCount(): int { return count($this->updatedCostCodes); }
    public function getSkippedCount(): int { return count($this->skippedCostCodes); }
    public function getBlockedCount(): int { return count($this->blockedCostCodes); }
    /** @return list<string> */
    public function getCreatedCostCodes(): array { return $this->createdCostCodes; }
    /** @return list<string> */
    public function getUpdatedCostCodes(): array { return $this->updatedCostCodes; }
    /** @return list<string> */
    public function getSkippedCostCodes(): array { return $this->skippedCostCodes; }
    /** @return list<string> */
    public function getBlockedCostCodes(): array { return $this->blockedCostCodes; }

    /** @return array<string,int> */
    public function getSummary(): array
    {
        return [
            'created' => $this->getCreatedCount(),
            'updated' => $this->getUpdatedCount(),
            'skipped' => $this->getSkippedCount(),
            'blocked' => $this->getBlockedCount(),
        ];
    }
}
