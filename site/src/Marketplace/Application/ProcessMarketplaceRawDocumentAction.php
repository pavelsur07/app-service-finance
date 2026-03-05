<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Facade\MarketplaceSyncFacade;

final class ProcessMarketplaceRawDocumentAction
{
    public function __construct(private readonly MarketplaceSyncFacade $syncFacade)
    {
    }

    public function __invoke(ProcessMarketplaceRawDocumentCommand $cmd): int
    {
        return match ($cmd->kind) {
            'sales' => $this->syncFacade->processSalesFromRaw($cmd->companyId, $cmd->rawDocId),
            'returns' => $this->syncFacade->processReturnsFromRaw($cmd->companyId, $cmd->rawDocId),
            'costs' => $this->syncFacade->processCostsFromRaw($cmd->companyId, $cmd->rawDocId),
            default => throw new \InvalidArgumentException('Unsupported kind: ' . $cmd->kind),
        };
    }
}

