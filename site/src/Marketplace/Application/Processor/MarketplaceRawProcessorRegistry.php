<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Enum\StagingRecordType;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final readonly class MarketplaceRawProcessorRegistry
{
    /** @var MarketplaceRawProcessorInterface[] */
    private array $processors;

    /**
     * @param iterable<MarketplaceRawProcessorInterface> $processors
     */
    public function __construct(
        #[TaggedIterator('marketplace.raw_processor')] iterable $processors
    ) {
        $this->processors = $processors instanceof \Traversable ? iterator_to_array($processors, false) : (array) $processors;
    }

    public function get(string|StagingRecordType $type, string $kind = ''): MarketplaceRawProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($type, $kind)) {
                return $processor;
            }
        }

        $typeName = $type instanceof StagingRecordType ? $type->value : $type;
        throw new \RuntimeException(sprintf('Processor not found for type "%s" and kind "%s"', $typeName, $kind));
    }
}
