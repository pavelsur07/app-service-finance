<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistry;
use App\Marketplace\Infrastructure\Query\MarketplaceRawDocumentMarketplaceQuery;

final class ProcessRawDocumentAction
{
    public function __construct(
        private readonly MarketplaceRawDocumentMarketplaceQuery $marketplaceQuery,
        private readonly MarketplaceRawProcessorRegistry $registry,
    ) {
    }

    public function __invoke(ProcessMarketplaceRawDocumentCommand $cmd): int
    {
        $marketplaceValue = $this->marketplaceQuery->getMarketplaceValue($cmd->companyId, $cmd->rawDocId);

        if ($marketplaceValue === null) {
            throw new \InvalidArgumentException("Raw document not found: {$cmd->rawDocId}");
        }

        $processor = $this->registry->get($marketplaceValue, $cmd->kind);

        return $processor->process($cmd->companyId, $cmd->rawDocId);
    }
}
