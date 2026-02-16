<?php

namespace App\Balance\Provider;

use App\Balance\Enum\BalanceLinkSourceType;

final class BalanceValueProviderRegistry
{
    /** @var array<string,BalanceValueProviderInterface> */
    private array $providersByType = [];

    /** @param iterable<BalanceValueProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            foreach (BalanceLinkSourceType::cases() as $type) {
                if (!$provider->supports($type)) {
                    continue;
                }

                if (isset($this->providersByType[$type->value])) {
                    throw new \LogicException(sprintf('Duplicate balance value provider for type "%s": "%s" and "%s".', $type->value, $this->providersByType[$type->value]::class, $provider::class));
                }

                $this->providersByType[$type->value] = $provider;
            }
        }
    }

    public function get(BalanceLinkSourceType $type): BalanceValueProviderInterface
    {
        if (!isset($this->providersByType[$type->value])) {
            throw new \LogicException(sprintf('No balance value provider found for type "%s".', $type->value));
        }

        return $this->providersByType[$type->value];
    }
}
