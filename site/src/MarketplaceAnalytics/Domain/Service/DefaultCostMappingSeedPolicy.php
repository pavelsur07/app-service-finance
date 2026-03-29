<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\Service;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use Ramsey\Uuid\Uuid;

final readonly class DefaultCostMappingSeedPolicy
{
    public function __construct(
        private UnitEconomyCostMappingRepositoryInterface $repository,
    ) {}

    public function seedForCompanyAndMarketplace(
        string $companyId,
        string $marketplace,
    ): void {
        $marketplaceType = MarketplaceType::from($marketplace);

        if ($this->repository->hasCompanyMappings($companyId, $marketplaceType)) {
            return;
        }

        $defaults = [
            'logistics_delivery' => UnitEconomyCostType::LOGISTICS_TO,
            'logistics_return' => UnitEconomyCostType::LOGISTICS_BACK,
            'storage' => UnitEconomyCostType::STORAGE,
            'commission' => UnitEconomyCostType::COMMISSION,
            'advertising_cpc' => UnitEconomyCostType::ADVERTISING_CPC,
            'advertising_other' => UnitEconomyCostType::ADVERTISING_OTHER,
        ];

        foreach ($defaults as $code => $type) {
            $mapping = new UnitEconomyCostMapping(
                Uuid::uuid7()->toString(),
                $companyId,
                $marketplaceType,
                $code,
                $type,
                isSystem: true,
            );
            $this->repository->save($mapping);
        }
    }
}
