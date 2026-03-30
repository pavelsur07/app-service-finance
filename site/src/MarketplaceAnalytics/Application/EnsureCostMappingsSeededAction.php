<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Domain\Service\DefaultCostMappingSeedPolicy;
use Doctrine\ORM\EntityManagerInterface;

final class EnsureCostMappingsSeededAction
{
    public function __construct(
        private readonly DefaultCostMappingSeedPolicy $seedPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(string $companyId, string $marketplace): void
    {
        $this->seedPolicy->seedForCompanyAndMarketplace($companyId, $marketplace);
        $this->entityManager->flush();
    }
}
