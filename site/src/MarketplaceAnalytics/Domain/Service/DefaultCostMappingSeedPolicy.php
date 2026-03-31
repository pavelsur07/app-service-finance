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

        if (!empty($this->repository->findByCompanyAndMarketplace($companyId, $marketplace))) {
            return;
        }

        $defaults = [
            ['id' => 'a0000001-0000-0000-0000-000000000001', 'name' => 'Логистика (доставка)', 'type' => UnitEconomyCostType::LOGISTICS_TO],
            ['id' => 'a0000001-0000-0000-0000-000000000002', 'name' => 'Логистика (возврат)', 'type' => UnitEconomyCostType::LOGISTICS_BACK],
            ['id' => 'a0000001-0000-0000-0000-000000000003', 'name' => 'Хранение', 'type' => UnitEconomyCostType::STORAGE],
            ['id' => 'a0000001-0000-0000-0000-000000000004', 'name' => 'Комиссия', 'type' => UnitEconomyCostType::COMMISSION],
            ['id' => 'a0000001-0000-0000-0000-000000000005', 'name' => 'Реклама (CPC)', 'type' => UnitEconomyCostType::ADVERTISING_CPC],
            ['id' => 'a0000001-0000-0000-0000-000000000006', 'name' => 'Реклама (прочая)', 'type' => UnitEconomyCostType::ADVERTISING_OTHER],
        ];

        foreach ($defaults as $default) {
            $mapping = new UnitEconomyCostMapping(
                Uuid::uuid7()->toString(),
                $companyId,
                $marketplaceType,
                $default['type'],
                $default['id'],
                $default['name'],
            );
            $this->repository->save($mapping);
        }
    }
}
