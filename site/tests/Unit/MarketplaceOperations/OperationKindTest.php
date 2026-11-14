<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceOperations;

use App\MarketplaceOperations\Enum\CascadeLevel;
use App\MarketplaceOperations\Enum\OperationKind;
use PHPUnit\Framework\TestCase;

final class OperationKindTest extends TestCase
{
    /**
     * Уровни утверждены Владельцем 13.11.2026: `stages/stage-1-category-levels.md` и `DATA.md` §9.2;
     * `compensation` (14.11) — прочий доход в общих затратах, Σ кабинета = by-day;
     * `mp_other_deductions` (14.11) — прочие удержания МП (шаблон ОПиУ `OPEX_WH_MP_DEDUCTIONS`), СМ3.
     */
    public function testEveryKindHasApprovedLevel(): void
    {
        $levels = [];
        foreach (OperationKind::cases() as $kind) {
            $levels[$kind->level()->value][] = $kind->value;
        }

        self::assertSame([
            CascadeLevel::REVENUE->value => ['sale', 'return'],
            CascadeLevel::INFO->value => ['spp', 'coinvestment'],
            CascadeLevel::MD1->value => ['commission', 'commission_brand', 'logistics_to', 'logistics_to_last_mile', 'logistics_to_dropoff', 'logistics_to_handover', 'logistics_back', 'logistics_back_pvz', 'acquiring', 'packaging_labor', 'packaging_materials', 'storage', 'stock_removal', 'disposal'],
            CascadeLevel::MD2->value => ['ads_cpc', 'ads_pay_per_order', 'promo_stars'],
            CascadeLevel::OVERHEAD->value => ['packaging_warehouse', 'promo_premium', 'promo_seller_bonus', 'promo_reviews', 'promo_chat_points', 'subscription', 'fine', 'service_correction', 'compensation', 'mp_other_deductions'],
        ], $levels);
    }
}
