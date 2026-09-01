<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Normalizer\Ozon;

use App\Marketplace\Infrastructure\Normalizer\Ozon\OzonProductCatalogNormalizer;
use PHPUnit\Framework\TestCase;

final class OzonProductCatalogNormalizerTest extends TestCase
{
    /**
     * Главный дефект задачи: у товара Ozon несколько SKU. На реальной выгрузке
     * 50 товаров дали 78 SKU — у 28 из них два источника (sds + fbs), и второй
     * sku в верхнеуровневое поле не попадает. Листинг, заведённый финансовым
     * документом по FBS-схеме, найдётся только по sources[].sku.
     */
    public function testCollectsEverySkuFromSourcesNotOnlyTheTopLevelOne(): void
    {
        $items = (new OzonProductCatalogNormalizer())->normalize($this->fixture());

        self::assertSame(['308520421', '308520498'], $items[0]->marketplaceSkus);
    }

    public function testPrimarySkuIsTheTopLevelOne(): void
    {
        $items = (new OzonProductCatalogNormalizer())->normalize($this->fixture());

        self::assertSame('308520421', $items[0]->primarySku);
    }

    public function testSingleSourceItemYieldsOneSku(): void
    {
        $items = (new OzonProductCatalogNormalizer())->normalize($this->fixture());

        self::assertSame(['2364427751'], $items[1]->marketplaceSkus);
    }

    public function testMapsNameAndOfferId(): void
    {
        $items = (new OzonProductCatalogNormalizer())->normalize($this->fixture());

        self::assertSame('Тестовый товар с двумя источниками', $items[0]->name);
        self::assertSame('TEST-ART-001/черный-M', $items[0]->offerId);
    }

    public function testMarketplaceCreatedAtComesFromTheProductNotFromOurRow(): void
    {
        $items = (new OzonProductCatalogNormalizer())->normalize($this->fixture());

        self::assertNotNull($items[0]->marketplaceCreatedAt);
        self::assertSame(
            '2021-08-24T14:15:19+00:00',
            $items[0]->marketplaceCreatedAt->format(\DATE_ATOM),
        );
    }

    public function testBarcodesAreCollected(): void
    {
        $items = (new OzonProductCatalogNormalizer())->normalize($this->fixture());

        self::assertSame(['4600000000017'], $items[0]->barcodes);
    }

    public function testMarketplaceDataCarriesCatalogPriceAndStatus(): void
    {
        $items = (new OzonProductCatalogNormalizer())->normalize($this->fixture());

        self::assertSame('3500.00', $items[0]->marketplaceData['price']);
        self::assertSame('Продается', $items[0]->marketplaceData['status_name']);
        self::assertFalse($items[0]->marketplaceData['is_archived']);
    }

    public function testItemWithoutAnySkuIsSkipped(): void
    {
        $items = (new OzonProductCatalogNormalizer())->normalize([
            'items' => [
                ['id' => 1, 'sku' => 0, 'sources' => [], 'offer_id' => 'NO-SKU', 'name' => 'Без SKU'],
            ],
        ]);

        self::assertSame([], $items);
    }

    /**
     * Характеризующий тест: написан ПОСЛЕ реализации. Отдельного production-кода
     * под него нет — поведение выпало из общего сбора SKU. Зафиксирован потому,
     * что молчаливая потеря товара здесь была бы тем же классом дефекта, ради
     * которого делается задача.
     */
    public function testItemWithoutTopLevelSkuStillYieldsSourceSkus(): void
    {
        $items = (new OzonProductCatalogNormalizer())->normalize([
            'items' => [
                [
                    'id' => 1,
                    'sku' => 0,
                    'offer_id' => 'ONLY-SOURCES',
                    'sources' => [['sku' => 777, 'source' => 'fbs']],
                ],
            ],
        ]);

        self::assertCount(1, $items);
        self::assertSame('777', $items[0]->primarySku);
        self::assertSame(['777'], $items[0]->marketplaceSkus);
    }

    public function testEmptyPayloadYieldsNoItems(): void
    {
        self::assertSame([], (new OzonProductCatalogNormalizer())->normalize([]));
    }

    public function testExtractsProductIdsFromProductListPage(): void
    {
        $ids = (new OzonProductCatalogNormalizer())->extractProductIds($this->productListFixture());

        self::assertSame([115526753, 2070985627], $ids);
    }

    public function testExtractsNoProductIdsFromEmptyPage(): void
    {
        self::assertSame([], (new OzonProductCatalogNormalizer())->extractProductIds([]));
    }

    /**
     * @return array<string, mixed>
     */
    private function productListFixture(): array
    {
        $path = \dirname(__DIR__, 5).'/Fixtures/Marketplace/Ozon/product_list.json';

        return json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = \dirname(__DIR__, 5).'/Fixtures/Marketplace/Ozon/product_info_list.json';

        return json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    }
}
