<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;

final class ProcessMarketplaceRawDocumentAction
{
    public function __construct(private readonly ProcessRawDocumentAction $processRawDocumentAction)
    {
    }

    public function __invoke(ProcessMarketplaceRawDocumentCommand $cmd): int
    {
        return ($this->processRawDocumentAction)($cmd);
    }
}
