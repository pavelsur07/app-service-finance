<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;

final readonly class DefaultSaleMappingApplyResult
{
    /**
     * @param list<string> $createdAmountSources
     * @param list<string> $skippedAmountSources
     */
    public function __construct(
        private MarketplaceType $marketplace,
        private array $createdAmountSources,
        private array $skippedAmountSources,
    ) {
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    /** @return list<string> */
    public function getCreatedAmountSources(): array
    {
        return $this->createdAmountSources;
    }

    /** @return list<string> */
    public function getSkippedAmountSources(): array
    {
        return $this->skippedAmountSources;
    }

    public function getCreatedCount(): int
    {
        return count($this->createdAmountSources);
    }

    public function getSkippedCount(): int
    {
        return count($this->skippedAmountSources);
    }

    /** @return array<string, int> */
    public function getSummary(): array
    {
        return [
            'created' => $this->getCreatedCount(),
            'skipped' => $this->getSkippedCount(),
        ];
    }
}
