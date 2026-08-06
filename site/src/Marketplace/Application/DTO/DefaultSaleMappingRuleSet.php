<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;

final readonly class DefaultSaleMappingRuleSet
{
    /** @var array<string, DefaultSaleMappingRule> */
    private array $rulesByAmountSource;

    /** @param DefaultSaleMappingRule[] $rules */
    public function __construct(
        private MarketplaceType $marketplace,
        array $rules,
    ) {
        $rulesByAmountSource = [];

        foreach ($rules as $rule) {
            $rulesByAmountSource[$rule->getAmountSource()->value] = $rule;
        }

        $this->rulesByAmountSource = $rulesByAmountSource;
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    /** @return DefaultSaleMappingRule[] */
    public function getRules(): array
    {
        return array_values($this->rulesByAmountSource);
    }

    public function count(): int
    {
        return count($this->rulesByAmountSource);
    }

    public function getByAmountSource(string $amountSource): ?DefaultSaleMappingRule
    {
        return $this->rulesByAmountSource[$amountSource] ?? null;
    }
}
