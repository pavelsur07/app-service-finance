<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Service;

use App\Marketplace\Ozon\Application\Service\OzonAccrualServiceCategoryResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Разбор услуг by-day в коды каталога Маркетплейса.
 *
 * Соответствие выведено не из имён, а сверкой с легаси-путём на проде: за
 * 01–07.09.2026 и 08–09.09.2026 суточный темп строк совпадает попарно —
 * Logistic 1074 против 975 в день, LastMileCourier 954 против 860,
 * ReturnFlowLogistic 138 против 141, PickUpPointReturnAcceptance 78 против 76.
 */
final class OzonAccrualServiceCategoryResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function servicesSeenInProduction(): iterable
    {
        yield 'Logistic' => ['32', 'Logistic', 'ozon_logistic_direct'];
        yield 'LastMileCourier' => ['29', 'LastMileCourier', 'ozon_logistic_last_mile'];
        yield 'ReturnFlowLogistic' => ['59', 'ReturnFlowLogistic', 'ozon_logistic_return'];
        yield 'PickUpPointReturnAcceptance' => ['45', 'PickUpPointReturnAcceptance', 'ozon_return_pvz'];
        yield 'Drop-Off Agent' => ['17', 'Drop-Off Agent', 'ozon_dropoff_apvz'];
        yield 'DeliveryToHandoverPlaceByOzon' => ['98', 'DeliveryToHandoverPlaceByOzon', 'ozon_delivery_to_handover_place'];
        yield 'Acquiring' => ['1', 'Acquiring', 'ozon_acquiring'];
        yield 'PayPerClick' => ['41', 'PayPerClick', 'ozon_cpc'];
        yield 'PackageCost' => ['38', 'PackageCost', 'ozon_package_materials'];
        yield 'PackingFee' => ['39', 'PackingFee', 'ozon_package_labor'];
        yield 'SupplyInbound' => ['77', 'SupplyInbound', 'ozon_supply_additional'];
        yield 'CrossDock' => ['12', 'CrossDock', 'ozon_crossdocking'];
        yield 'Placements' => ['46', 'Placements', 'ozon_storage'];
        yield 'TemporaryPlacementsAgent' => ['79', 'TemporaryPlacementsAgent', 'ozon_storage_partner'];
        yield 'AcceleratedReviewCollection' => ['96', 'AcceleratedReviewCollection', 'ozon_reviews'];
        yield 'EarlyPayment' => ['18', 'EarlyPayment', 'ozon_early_payment'];
        yield 'Disposal' => ['15', 'Disposal', 'ozon_disposal'];
        yield 'ItemCompensation' => ['25', 'ItemCompensation', 'ozon_compensation'];
        // В справочнике by-day имя без «d» на конце, а у легаси-операции с ним:
        // DefectFineShipmentDelayRate против DefectFineShipmentDelayRated.
        yield 'DefectFineShipmentDelayRate' => ['94', 'DefectFineShipmentDelayRate', 'ozon_fines_shipment_delay_rated'];
        yield 'BrandCommission' => ['3', 'BrandCommission', 'ozon_brand_commission'];
        yield 'StarsMembership' => ['74', 'StarsMembership', 'ozon_stars_membership'];
        yield 'StockInsurance' => ['76', 'StockInsurance', 'ozon_stock_insurance'];
        yield 'LabelBrandVerified' => ['118', 'LabelBrandVerified', 'ozon_brand_verified'];
        yield 'PushCampaign' => ['55', 'PushCampaign', 'ozon_sending_push_notifications'];
        // Справочник Ozon описывает ItemPacking как «Дополнительная упаковка на
        // складе Ozon» — слово в слово имя нашей категории, и это отдельная
        // услуга от PackingFee («Упаковка товара партнёрами»).
        yield 'ItemPacking' => ['84', 'ItemPacking', 'ozon_additional_packaging_warehouse'];
        yield 'Promotion' => ['54', 'Promotion', 'ozon_marketing_action'];
        yield 'SellerReturns' => ['71', 'SellerReturns', 'ozon_return_from_stock'];
        // До 26.09.2026 уходила в ozon_unknown_52: 24 990 и 9 990 руб. в том же
        // ритме, в каком с января приходили в ozon_premium_promotion легаси-путём.
        yield 'PremiumSubscription' => ['52', 'PremiumSubscription', 'ozon_premium_promotion'];
        // «Краткосрочное размещение возврата FBS» — решение Владельца от 26.09.2026.
        yield 'TemporaryPlacement' => ['78', 'TemporaryPlacement', 'ozon_temporary_storage'];
        // Compensation разводится по знаку отдельными тестами ниже: направление
        // у неё кодируется категорией, а не только видом операции.
    }

    /**
     * Услуги, размеченные до первого прихода денег в by-day: описание Ozon
     * совпадает по смыслу с категорией каталога, и эта категория уже получала
     * деньги легаси-путём. Без разметки первая же такая затрата ушла бы в
     * «неразобранные» мимо ОПиУ — как PremiumSubscription в сентябре.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function servicesMarkedAhead(): iterable
    {
        yield 'PremiumCashbackPromotion' => ['49', 'PremiumCashbackPromotion', 'ozon_premium_promotion'];
        yield 'Charity' => ['7', 'Charity', 'ozon_charity'];
        yield 'LabelOriginal' => ['27', 'LabelOriginal', 'ozon_original_label'];
        yield 'Installment' => ['22', 'Installment', 'ozon_installment'];
        yield 'InternetSiteAdvertising' => ['23', 'InternetSiteAdvertising', 'ozon_site_advertising'];
        yield 'PremiumCashbackIndividualPoints' => ['48', 'PremiumCashbackIndividualPoints', 'ozon_premium_cashback'];
        yield 'PremiumMailingCommission' => ['50', 'PremiumMailingCommission', 'ozon_seller_bonus'];
        yield 'PointsForReviews' => ['47', 'PointsForReviews', 'ozon_reviews'];
        yield 'SaleReview' => ['70', 'SaleReview', 'ozon_reviews'];
        yield 'Replenishment' => ['58', 'Replenishment', 'ozon_warehouse_movement'];
        yield 'DefectFineProhibitedGoods' => ['90', 'DefectFineProhibitedGoods', 'ozon_fines_prohibited_products'];
        yield 'RfbsServiceFee' => ['68', 'RfbsServiceFee', 'ozon_service_fee_rfbs'];
        yield 'LastMile' => ['28', 'LastMile', 'ozon_logistic_last_mile'];
    }

    #[DataProvider('servicesMarkedAhead')]
    public function testMarkedAheadServiceResolvesToItsMarketplaceCode(string $typeId, string $typeName, string $expectedCode): void
    {
        $resolver = new OzonAccrualServiceCategoryResolver();

        self::assertSame($expectedCode, $resolver->resolve($typeId, $typeName, -100.0)['code']);
    }

    #[DataProvider('servicesMarkedAhead')]
    public function testMarkedAheadServiceHasDefaultPlRule(string $typeId, string $typeName, string $expectedCode): void
    {
        $rules = Yaml::parseFile(__DIR__.'/../../../../../../config/marketplace/default_cost_mapping.yaml');

        self::assertContains($expectedCode, array_column($rules['marketplaces']['ozon']['cost_mappings'], 'cost_code'));
    }

    public function testPositiveCompensationIsIncomeCategory(): void
    {
        // Легаси-путь с января разводит компенсацию по знаку на две категории:
        // 66 строк дохода и 51 расхода. Сложить их в одну значило бы разорвать
        // отчёт по категориям на сентябре.
        $resolver = new OzonAccrualServiceCategoryResolver();

        self::assertSame('ozon_compensation', $resolver->resolve('10', 'Compensation', 1954.0)['code']);
    }

    public function testNegativeCompensationIsExpenseCategory(): void
    {
        // Единственная запись by-day за 10.09.2026 пришла именно такой: −1954,
        // то есть Ozon списал с продавца.
        $resolver = new OzonAccrualServiceCategoryResolver();

        self::assertSame('ozon_decompensation', $resolver->resolve('10', 'Compensation', -1954.0)['code']);
    }

    #[DataProvider('servicesSeenInProduction')]
    public function testServiceResolvesToItsMarketplaceCode(string $typeId, string $typeName, string $expectedCode): void
    {
        $resolver = new OzonAccrualServiceCategoryResolver();

        self::assertSame($expectedCode, $resolver->resolve($typeId, $typeName, -100.0)['code']);
    }

    /**
     * Главный инвариант этапа: каждая услуга, которую Ozon реально присылает,
     * обязана получить код, у которого есть правило в дефолтном маппинге ОПиУ.
     * Иначе затрата заводится, но до отчёта не доходит — молча, без ошибки.
     */
    #[DataProvider('servicesSeenInProduction')]
    public function testResolvedCodeHasDefaultPlRule(string $typeId, string $typeName, string $expectedCode): void
    {
        $rules = Yaml::parseFile(__DIR__.'/../../../../../../config/marketplace/default_cost_mapping.yaml');
        $codes = array_column($rules['marketplaces']['ozon']['cost_mappings'], 'cost_code');

        $resolver = new OzonAccrualServiceCategoryResolver();
        $code = $resolver->resolve($typeId, $typeName, -100.0)['code'];

        self::assertContains($code, $codes, sprintf('Для кода "%s" нет правила в default_cost_mapping.yaml.', $code));
    }

    public function testPlainShipmentDelayFineIsNotClaimedByTheRatedOne(): void
    {
        // Штраф за просроченную отгрузку и штраф за нерекомендованный слот —
        // разные категории. Имя справочника принадлежит второму; первый из
        // by-day не приходит, и выдавать его за второй нельзя.
        $resolver = new OzonAccrualServiceCategoryResolver();

        self::assertSame('ozon_unknown_93', $resolver->resolve('93', 'DefectFineShipmentDelay', -100.0)['code']);
    }

    public function testUnknownServiceGetsItsOwnVisibleCode(): void
    {
        $resolver = new OzonAccrualServiceCategoryResolver();

        $category = $resolver->resolve('9001', 'SomeBrandNewOzonService', -100.0);

        self::assertSame('ozon_unknown_9001', $category['code']);
        self::assertStringContainsString('SomeBrandNewOzonService', $category['name']);
    }

    public function testServiceWithoutNameIsNotGuessed(): void
    {
        $resolver = new OzonAccrualServiceCategoryResolver();

        self::assertSame('ozon_unknown_777', $resolver->resolve('777', null, -100.0)['code']);
    }
}
