<?php

declare(strict_types=1);

namespace App\Finance\Application\Command;

final readonly class ImportPLCategoryTreeCommand
{
    public function __construct(
        public string $sourceCompanyId,
        public string $targetCompanyId,
        public bool $dryRun,
    ) {
    }
}
