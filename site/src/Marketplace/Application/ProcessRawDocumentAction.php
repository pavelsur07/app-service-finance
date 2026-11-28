<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceRawDocumentMarketplaceQueryInterface;

final class ProcessRawDocumentAction implements ProcessRawDocumentActionInterface
{
    public function __construct(
        private readonly MarketplaceRawDocumentMarketplaceQueryInterface $marketplaceQuery,
        private readonly MarketplaceRawProcessorRegistryInterface $registry,
    ) {
    }

    public function __invoke(ProcessMarketplaceRawDocumentCommand $cmd): int
    {
        $source = $this->marketplaceQuery->findDocumentSource($cmd->companyId, $cmd->rawDocId);

        if (null === $source) {
            throw new \InvalidArgumentException("Raw document not found: {$cmd->rawDocId}");
        }

        $marketplace = MarketplaceType::from($source['marketplace']);
        if ('costs' === $cmd->kind) {
            $format = MarketplaceRawFormat::forProcessing($source['api_endpoint'], $cmd->rawDocId);
            $processor = $this->registry->getForDocument(StagingRecordType::COST, $marketplace, $cmd->kind, $format);
        } else {
            $processor = $this->registry->getForDocument($marketplace->value, $marketplace, $cmd->kind);
        }

        return $processor->process($cmd->companyId, $cmd->rawDocId);
    }
}
