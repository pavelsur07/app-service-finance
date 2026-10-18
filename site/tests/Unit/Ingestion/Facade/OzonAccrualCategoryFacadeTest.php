<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Facade;

use App\Ingestion\Facade\OzonAccrualCategoryFacade;
use PHPUnit\Framework\TestCase;

/**
 * Справочник Ozon /v1/finance/accrual/types отдаёт английские коды услуг.
 * Имена и идентификаторы в тестах — из обезличенной реальной выгрузки за
 * июнь 2026, см. tests/Fixtures/Marketplace/Ozon/accrual_types.json.
 */
final class OzonAccrualCategoryFacadeTest extends TestCase
{
    public function testResolvesKnownServiceByDictionaryName(): void
    {
        $view = $this->facade()->resolveByServiceType('32', 'Logistic');

        self::assertSame('ozon_logistics', $view->code);
        self::assertSame('Логистика', $view->label);
        self::assertSame('Услуги доставки', $view->group);
        self::assertTrue($view->known);
        self::assertSame('32', $view->typeId);
        self::assertSame('Logistic', $view->typeName);
    }

    public function testResolvesAcquiringByDictionaryName(): void
    {
        $view = $this->facade()->resolveByServiceType('1', 'Acquiring');

        self::assertSame('ozon_acquiring', $view->code);
        self::assertTrue($view->known);
    }

    /**
     * Регрессия. Прежняя карта typeIds внутри Ingestion считала 29 логистикой,
     * тогда как 29 — LastMileCourier, а Logistic это 32. Статья выбирается по
     * имени услуги из справочника Ozon и сверена с его `description`.
     */
    public function testLastMileCourierResolvesToPartnerLastMileNotLogistics(): void
    {
        $view = $this->facade()->resolveByServiceType('29', 'LastMileCourier');

        self::assertSame('ozon_delivery_to_pickup_partners', $view->code);
        self::assertTrue($view->known);
        self::assertSame('Услуги партнёров', $view->group);
    }

    public function testReturnAcceptanceResolvesToPartnerReturnsNotDelivery(): void
    {
        // 45 в справочнике — PickUpPointReturnAcceptance, «Обработка возвратов,
        // отмен и невыкупов партнёрами»; прежняя карта давала «Доставку силами Ozon».
        $view = $this->facade()->resolveByServiceType('45', 'PickUpPointReturnAcceptance');

        self::assertSame('ozon_partner_return_processing', $view->code);
        self::assertTrue($view->known);
    }

    public function testTypeIdAloneNeverPicksCategory(): void
    {
        $view = $this->facade()->resolveByServiceType('29', null);

        self::assertFalse($view->known);
        self::assertSame('Требует классификации', $view->group);
    }

    public function testUnknownServiceCarriesTypeIdForManualMapping(): void
    {
        $view = $this->facade()->resolveByServiceType('4242', 'СовершенноНоваяУслуга');

        self::assertFalse($view->known);
        self::assertSame('Требует классификации', $view->group);
        self::assertStringContainsString('4242', $view->code);
        self::assertSame('4242', $view->typeId);
    }

    public function testMissingInputDegradesInsteadOfThrowing(): void
    {
        $view = $this->facade()->resolveByServiceType(null, null);

        self::assertFalse($view->known);
        self::assertSame('Требует классификации', $view->group);
        self::assertNotSame('', $view->code);
    }

    private function facade(): OzonAccrualCategoryFacade
    {
        return new OzonAccrualCategoryFacade();
    }
}
