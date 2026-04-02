<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\ProcessingPipelineRun;
use App\Marketplace\Enum\MarketplaceType;

interface ProcessingPipelineRunRepositoryInterface
{
    public function save(ProcessingPipelineRun $run): void;

    public function findByCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
    ): ?ProcessingPipelineRun;

    /**
     * @return ProcessingPipelineRun[]
     */
    public function findAllForCompany(string $companyId): array;
}
