<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application\Service;

use App\Marketplace\Application\DTO\CostCategoryGroupsDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\CostCategoryCatalogFacade;

final class MarketplaceCostAnalyticsGroupResolver
{
    /** @var array<string, CostCategoryGroupsDTO>|null */
    private ?array $ozonGroups = null;

    /** @var array<string, CostCategoryGroupsDTO>|null */
    private ?array $wbGroups = null;

    public function __construct(
        private readonly CostCategoryCatalogFacade $costCategoryCatalog,
    ) {
    }

    public function resolveWidgetGroup(?string $marketplace, string $code, string $name): string
    {
        if ($marketplace === MarketplaceType::WILDBERRIES->value) {
            return $this->wb($code)->widgetGroup ?? 'Другие услуги и штрафы';
        }

        if ($marketplace === MarketplaceType::OZON->value) {
            return $this->ozon($code)->widgetGroup ?? 'Другие услуги и штрафы';
        }

        return $this->ozon($code)->widgetGroup
            ?? $this->wb($code)->widgetGroup
            ?? 'Другие услуги и штрафы';
    }

    public function resolveBreakdownGroup(?string $marketplace, string $code, string $name): string
    {
        if ($marketplace === MarketplaceType::WILDBERRIES->value) {
            return $this->wb($code)->breakdownGroup ?? 'Другие услуги и штрафы';
        }

        if ($marketplace === MarketplaceType::OZON->value) {
            return $this->ozon($code)->breakdownGroup ?? 'Другие услуги и штрафы';
        }

        return $this->ozon($code)->breakdownGroup
            ?? $this->wb($code)->breakdownGroup
            ?? 'Другие услуги и штрафы';
    }

    public function resolveUnitBucket(?string $marketplace, string $code, string $name): string
    {
        if ($marketplace === MarketplaceType::WILDBERRIES->value) {
            return $this->wb($code)->unitBucket ?? 'other';
        }

        if ($marketplace === MarketplaceType::OZON->value) {
            if (in_array($code, ['ozon_sale_commission', 'ozon_brand_commission'], true)) {
                return 'commission';
            }

            $ozon = $this->ozon($code);
            if (null !== $ozon) {
                return 'Услуги доставки' === $ozon->breakdownGroup ? 'logistics' : 'other';
            }

            return 'other';
        }

        $ozon = $this->ozon($code);
        if (null !== $ozon) {
            if (in_array($code, ['ozon_sale_commission', 'ozon_brand_commission'], true)) {
                return 'commission';
            }

            return 'Услуги доставки' === $ozon->breakdownGroup ? 'logistics' : 'other';
        }

        return $this->wb($code)->unitBucket ?? 'other';
    }

    private function ozon(string $code): ?CostCategoryGroupsDTO
    {
        $this->ozonGroups ??= $this->costCategoryCatalog->ozonCostCategoryGroups();

        return $this->ozonGroups[$code] ?? null;
    }

    private function wb(string $code): ?CostCategoryGroupsDTO
    {
        $this->wbGroups ??= $this->costCategoryCatalog->wbCostCategoryGroups();

        return $this->wbGroups[$code] ?? null;
    }
}
