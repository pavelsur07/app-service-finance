<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Enum\MarketplaceType;
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
        $marketplaceValue = $this->marketplaceQuery->getMarketplaceValue($cmd->companyId, $cmd->rawDocId);

        if ($marketplaceValue === null) {
            throw new \InvalidArgumentException("Raw document not found: {$cmd->rawDocId}");
        }

        $marketplace = MarketplaceType::from($marketplaceValue);
        $processor   = $this->registry->get($marketplaceValue, $marketplace, $cmd->kind);

        return $processor->process($cmd->companyId, $cmd->rawDocId);
    }
}
