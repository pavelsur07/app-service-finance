<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;

final readonly class DefaultCostMappingRuleSet
{
    /** @var array<string, DefaultCostMappingRule> */
    private array $rulesByCostCode;

    /** @param DefaultCostMappingRule[] $rules */
    public function __construct(
        private MarketplaceType $marketplace,
        array $rules,
    ) {
        $this->rulesByCostCode = [];

        foreach ($rules as $rule) {
            $this->rulesByCostCode[$rule->getCostCode()] = $rule;
        }
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    /** @return DefaultCostMappingRule[] */
    public function getRules(): array
    {
        return array_values($this->rulesByCostCode);
    }

    public function count(): int
    {
        return count($this->rulesByCostCode);
    }

    public function getByCostCode(string $costCode): ?DefaultCostMappingRule
    {
        return $this->rulesByCostCode[$costCode] ?? null;
    }
}
