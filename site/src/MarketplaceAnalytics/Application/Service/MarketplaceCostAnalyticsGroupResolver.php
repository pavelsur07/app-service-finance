<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application\Service;

use App\Marketplace\Domain\OzonCostCategory;
use App\Marketplace\Domain\WbCostCategory;
use App\Marketplace\Enum\MarketplaceType;

final class MarketplaceCostAnalyticsGroupResolver
{
    public function resolveWidgetGroup(?string $marketplace, string $code, string $name): string
    {
        if ($marketplace === MarketplaceType::WILDBERRIES->value) {
            return WbCostCategory::byCode()[$code]->widgetGroup ?? 'Другие услуги и штрафы';
        }

        if ($marketplace === MarketplaceType::OZON->value) {
            foreach (OzonCostCategory::all() as $c) {
                if ($c->code === $code) {
                    return $c->widgetGroup;
                }
            }

            return 'Другие услуги и штрафы';
        }

        foreach (OzonCostCategory::all() as $c) {
            if ($c->code === $code) {
                return $c->widgetGroup;
            }
        }

        return WbCostCategory::byCode()[$code]->widgetGroup ?? 'Другие услуги и штрафы';
    }

    public function resolveBreakdownGroup(?string $marketplace, string $code, string $name): string
    {
        if ($marketplace === MarketplaceType::WILDBERRIES->value) {
            return WbCostCategory::byCode()[$code]->breakdownGroup ?? 'Другие услуги и штрафы';
        }

        if ($marketplace === MarketplaceType::OZON->value) {
            foreach (OzonCostCategory::all() as $c) {
                if ($c->code === $code) {
                    return $c->xlsxGroup;
                }
            }

            return 'Другие услуги и штрафы';
        }

        foreach (OzonCostCategory::all() as $c) {
            if ($c->code === $code) {
                return $c->xlsxGroup;
            }
        }

        return WbCostCategory::byCode()[$code]->breakdownGroup ?? 'Другие услуги и штрафы';
    }

    public function resolveUnitBucket(?string $marketplace, string $code, string $name): string
    {
        if ($marketplace === MarketplaceType::WILDBERRIES->value) {
            return WbCostCategory::byCode()[$code]->unitBucket ?? 'other';
        }

        if ($marketplace === MarketplaceType::OZON->value) {
            if (in_array($code, ['ozon_sale_commission', 'ozon_brand_commission'], true)) {
                return 'commission';
            }

            foreach (OzonCostCategory::all() as $c) {
                if ($c->code === $code) {
                    return $c->xlsxGroup === 'Услуги доставки' ? 'logistics' : 'other';
                }
            }

            return 'other';
        }

        foreach (OzonCostCategory::all() as $c) {
            if ($c->code === $code) {
                if (in_array($c->code, ['ozon_sale_commission', 'ozon_brand_commission'], true)) {
                    return 'commission';
                }

                return $c->xlsxGroup === 'Услуги доставки' ? 'logistics' : 'other';
            }
        }

        return WbCostCategory::byCode()[$code]->unitBucket ?? 'other';
    }
}
