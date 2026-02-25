<?php

namespace App\Marketplace\Service\Integration;

use App\Marketplace\Enum\MarketplaceType;

class MarketplaceAdapterRegistry
{
    /** @var array<string, MarketplaceAdapterInterface> */
    private array $adapters = [];

    /**
     * @param iterable<MarketplaceAdapterInterface> $adapters
     */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->getMarketplaceType()] = $adapter;
        }
    }

    public function get(MarketplaceType $marketplace): MarketplaceAdapterInterface
    {
        $key = $marketplace->value;

        if (!isset($this->adapters[$key])) {
            throw new \RuntimeException(sprintf(
                'Адаптер для маркетплейса "%s" не зарегистрирован. Доступные: %s',
                $marketplace->getDisplayName(),
                implode(', ', array_keys($this->adapters))
            ));
        }

        return $this->adapters[$key];
    }

    public function has(MarketplaceType $marketplace): bool
    {
        return isset($this->adapters[$marketplace->value]);
    }

    /**
     * @return MarketplaceType[]
     */
    public function getAvailableMarketplaces(): array
    {
        return array_map(
            fn(string $value) => MarketplaceType::from($value),
            array_keys($this->adapters)
        );
    }
}
