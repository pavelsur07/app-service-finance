<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;

interface ProcessRawDocumentActionInterface
{
    public function __invoke(ProcessMarketplaceRawDocumentCommand $cmd): int;
}
