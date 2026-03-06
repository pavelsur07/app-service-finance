<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

final class MarketplaceRawProcessorRegistry
{
    /** @param iterable<MarketplaceRawProcessorInterface> $processors */
    public function __construct(private readonly iterable $processors)
    {
    }

    public function get(string|\App\Marketplace\Enum\StagingRecordType $type, string $kind = ''): MarketplaceRawProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($type, $kind)) {
                return $processor;
            }
        }

        $typeName = $type instanceof \App\Marketplace\Enum\StagingRecordType ? $type->value : $type;
        throw new \RuntimeException(sprintf('Processor not found for type "%s" and kind "%s"', $typeName, $kind));
    }
}
