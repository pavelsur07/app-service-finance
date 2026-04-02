<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\ProcessingKind;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final readonly class MarketplaceRawProcessorRegistry implements MarketplaceRawProcessorRegistryInterface
{
    /** @var MarketplaceRawProcessorInterface[] */
    private array $processors;

    /**
     * @param iterable<MarketplaceRawProcessorInterface> $processors
     */
    public function __construct(
        #[TaggedIterator('app.marketplace.raw_processor')] iterable $processors,
        private MarketplaceRawDocumentRepository $rawDocumentRepository,
    ) {
        $this->processors = $processors instanceof \Traversable ? iterator_to_array($processors, false) : (array) $processors;
    }

    public function get(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): MarketplaceRawProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($type, $marketplace, $kind)) {
                return $processor;
            }
        }

        $typeName = $type instanceof StagingRecordType ? $type->value : $type;
        throw new \RuntimeException(sprintf('Processor not found for type "%s", marketplace "%s", kind "%s"', $typeName, $marketplace->value, $kind));
    }

    public function process(
        string $companyId,
        MarketplaceType $marketplace,
        ProcessingKind $kind,
    ): int {
        $docs = $this->rawDocumentRepository->findByCompanyAndMarketplace($companyId, $marketplace);

        if ($docs === []) {
            return 0;
        }

        $processor = $this->get($marketplace->value, $marketplace, $kind->value);

        $total = 0;
        foreach ($docs as $doc) {
            $total += $processor->process($companyId, $doc->getId());
        }

        return $total;
    }
}
