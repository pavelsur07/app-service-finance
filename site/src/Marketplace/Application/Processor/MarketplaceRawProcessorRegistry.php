<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

final class MarketplaceRawProcessorRegistry
{
    /** @param iterable<MarketplaceRawProcessorInterface> $processors */
    public function __construct(private readonly iterable $processors)
    {
    }

    public function get(string $marketplaceValue, string $kind): MarketplaceRawProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($marketplaceValue, $kind)) {
                return $processor;
            }
        }

        throw new \RuntimeException("No processor for marketplace={$marketplaceValue}, kind={$kind}");
    }
}
