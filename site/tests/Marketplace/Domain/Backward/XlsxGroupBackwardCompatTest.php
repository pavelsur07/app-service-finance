<?php

declare(strict_types=1);

namespace App\Tests\Marketplace\Domain\Backward;

use App\Marketplace\Application\Reconciliation\OzonXlsxServiceGroupMap;
use PHPUnit\Framework\TestCase;

/**
 * Backward-compatibility тест: гарантирует что getCategoryToServiceGroup()
 * возвращает те же xlsx groups для каждого category code.
 *
 * EXPECTED_XLSX_GROUPS — эталонный снимок маппинга OzonXlsxServiceGroupMap
 * на момент коммита 65463bf (2026-04-16), до рефакторинга OzonCostCategory.
 *
 * Если маппинг изменится — тест покажет какой именно category_code разошёлся.
 */
final class XlsxGroupBackwardCompatTest extends TestCase
{
    /**
     * Эталонный маппинг: category_code => xlsx service group.
     *
     * @var array<string, string>
     */
    private const EXPECTED_XLSX_GROUPS = [
        // === Вознаграждение Ozon ===
        'ozon_sale_commission'       => 'Вознаграждение Ozon',
        'ozon_brand_commission'      => 'Вознаграждение Ozon',

        // === Продвижение и реклама ===
        'ozon_cpc'                   => 'Продвижение и реклама',
        'ozon_premium_promotion'     => 'Продвижение и реклама',
        'ozon_premium_cashback'      => 'Продвижение и реклама',
        'ozon_reviews'               => 'Продвижение и реклама',
        'ozon_seller_bonus'          => 'Продвижение и реклама',
        'ozon_marketing_action'      => 'Продвижение и реклама',
        'ozon_stars_membership'      => 'Продвижение и реклама',

        // === Услуги доставки ===
        'ozon_logistic_direct'       => 'Услуги доставки',
        'ozon_logistic_direct_vdc'   => 'Услуги доставки',
        'ozon_logistic_direct_trans' => 'Услуги доставки',
        'ozon_logistic_delivery'     => 'Услуги доставки',
        'ozon_logistic_kgt'          => 'Услуги доставки',
        'ozon_logistic_return'       => 'Услуги доставки',
        'ozon_logistic_return_trans' => 'Услуги доставки',
        'ozon_logistic_inbound'      => 'Услуги доставки',
        'ozon_logistic_inbound_seller' => 'Услуги доставки',
        'ozon_dropoff_pvz'           => 'Услуги доставки',
        'ozon_dropoff_ff'            => 'Услуги доставки',
        'ozon_dropoff_sc'            => 'Услуги доставки',
        'ozon_dropoff_ppz'           => 'Услуги доставки',
        'ozon_delivery'              => 'Услуги доставки',
        'ozon_return_delivery'       => 'Услуги доставки',
        'ozon_return_partial'        => 'Услуги доставки',
        'ozon_return_not_delivered'  => 'Услуги доставки',
        'ozon_return_after_delivery' => 'Услуги доставки',
        'ozon_return_storage_pvz'    => 'Услуги доставки',
        'ozon_return_storage_wh'     => 'Услуги доставки',

        // === Услуги FBO ===
        'ozon_crossdocking'          => 'Услуги FBO',
        'ozon_supply_shortage'       => 'Услуги FBO',
        'ozon_return_from_stock'     => 'Услуги FBO',
        'ozon_supply_additional'     => 'Услуги FBO',
        'ozon_supply_surplus'        => 'Услуги FBO',
        'ozon_storage'               => 'Услуги FBO',
        'ozon_logistic_pickup'       => 'Услуги FBO',
        'ozon_package_materials'     => 'Услуги FBO',
        'ozon_package_labor'         => 'Услуги FBO',
        'ozon_warehouse_movement'    => 'Услуги FBO',

        // === Услуги партнёров ===
        'ozon_logistic_last_mile'    => 'Услуги партнёров',
        'ozon_return_pvz'            => 'Услуги партнёров',
        'ozon_storage_partner'       => 'Услуги партнёров',
        'ozon_acquiring'             => 'Услуги партнёров',
        'ozon_fulfillment'           => 'Услуги партнёров',
        'ozon_dropoff_apvz'          => 'Услуги партнёров',

        // === Другие услуги и штрафы ===
        'ozon_disposal'              => 'Другие услуги и штрафы',
        'ozon_ovh_processing'        => 'Другие услуги и штрафы',
        'ozon_penalty_undeliverable' => 'Другие услуги и штрафы',
        'ozon_marking'               => 'Другие услуги и штрафы',
        'ozon_agency_fee'            => 'Другие услуги и штрафы',
        'ozon_early_payment'         => 'Другие услуги и штрафы',
        'ozon_flexible_payment'      => 'Другие услуги и штрафы',
        'ozon_installment'           => 'Другие услуги и штрафы',
        'ozon_premium_correction'    => 'Другие услуги и штрафы',
        'ozon_service_correction'    => 'Другие услуги и штрафы',
        'ozon_other_service'         => 'Другие услуги и штрафы',

        // === Компенсации и декомпенсации ===
        'ozon_compensation'          => 'Компенсации и декомпенсации',
        'ozon_decompensation'        => 'Компенсации и декомпенсации',
    ];

    public function test_xlsx_groups_unchanged_after_refactoring(): void
    {
        $actual = OzonXlsxServiceGroupMap::getCategoryToServiceGroup();

        foreach (self::EXPECTED_XLSX_GROUPS as $code => $expectedGroup) {
            $this->assertArrayHasKey(
                $code,
                $actual,
                sprintf('Category "%s" missing from xlsx group map', $code),
            );
            $this->assertSame(
                $expectedGroup,
                $actual[$code],
                sprintf('Xlsx group changed for "%s"', $code),
            );
        }

        // Нет новых кодов, которых не было раньше
        foreach ($actual as $code => $group) {
            $this->assertArrayHasKey(
                $code,
                self::EXPECTED_XLSX_GROUPS,
                sprintf('New unexpected category "%s" appeared', $code),
            );
        }
    }

    public function test_expected_xlsx_groups_count(): void
    {
        $this->assertCount(
            58,
            self::EXPECTED_XLSX_GROUPS,
            'EXPECTED_XLSX_GROUPS should contain all 58 entries from the baseline map',
        );
    }
}
