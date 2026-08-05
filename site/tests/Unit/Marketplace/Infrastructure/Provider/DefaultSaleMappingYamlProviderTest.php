<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Provider;

use App\Marketplace\Application\Exception\DefaultSaleMappingConfigException;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Provider\DefaultSaleMappingYamlProvider;
use PHPUnit\Framework\TestCase;

final class DefaultSaleMappingYamlProviderTest extends TestCase
{
    private const DEFAULT_CONFIG_PATH = __DIR__.'/../../../../../config/marketplace/default_sale_mapping.yaml';
    private const FIXTURES_DIR = __DIR__.'/../../../../Fixtures/Marketplace/SaleProvider';

    public function testRealConfigDescribesBothMarketplaces(): void
    {
        $provider = new DefaultSaleMappingYamlProvider(self::DEFAULT_CONFIG_PATH);

        $ozon = $provider->getForMarketplace(MarketplaceType::OZON);
        $wb = $provider->getForMarketplace(MarketplaceType::WILDBERRIES);

        self::assertSame(6, $ozon->count());
        self::assertSame(6, $wb->count());

        // Выручка с СПП у Ozon берётся из отчёта реализации, у WB — из total_revenue.
        self::assertSame('REV_SPP_SALES', $ozon->getByAmountSource(AmountSource::SALE_REALIZATION->value)?->getPlCode());
        self::assertSame('REV_SPP_SALES', $wb->getByAmountSource(AmountSource::SALE_REVENUE->value)?->getPlCode());
        self::assertNull($wb->getByAmountSource(AmountSource::SALE_REALIZATION->value));
    }

    public function testMarketplaceWithoutRulesReturnsEmptyRuleSet(): void
    {
        $provider = new DefaultSaleMappingYamlProvider(self::DEFAULT_CONFIG_PATH);

        $ruleSet = $provider->getForMarketplace(MarketplaceType::YANDEX_MARKET);

        self::assertSame(0, $ruleSet->count());
        self::assertSame(MarketplaceType::YANDEX_MARKET, $ruleSet->getMarketplace());
    }

    public function testDescriptionIsOptionalAndTrimmed(): void
    {
        $provider = new DefaultSaleMappingYamlProvider(self::DEFAULT_CONFIG_PATH);
        $ozon = $provider->getForMarketplace(MarketplaceType::OZON);

        self::assertSame('Возврат с СПП Ozon', $ozon->getByAmountSource(AmountSource::RETURN_REALIZATION->value)?->getDescription());
        self::assertNull($ozon->getByAmountSource(AmountSource::SALE_GROSS->value)?->getDescription());
    }

    public function testOperationTypeIsDerivedFromAmountSource(): void
    {
        $provider = new DefaultSaleMappingYamlProvider(self::DEFAULT_CONFIG_PATH);
        $ozon = $provider->getForMarketplace(MarketplaceType::OZON);

        self::assertSame('sale', $ozon->getByAmountSource(AmountSource::SALE_GROSS->value)?->getOperationType());
        self::assertSame('return', $ozon->getByAmountSource(AmountSource::RETURN_GROSS->value)?->getOperationType());
    }

    public function testMissingFileThrowsException(): void
    {
        $provider = new DefaultSaleMappingYamlProvider(self::FIXTURES_DIR.'/does_not_exist.yaml');

        $this->expectException(DefaultSaleMappingConfigException::class);
        $provider->getAll();
    }

    public function testUnsupportedVersionThrowsException(): void
    {
        $provider = new DefaultSaleMappingYamlProvider(self::FIXTURES_DIR.'/unsupported_version.yaml');

        $this->expectException(DefaultSaleMappingConfigException::class);
        $this->expectExceptionMessageMatches('/version/i');
        $provider->getAll();
    }

    public function testDuplicateAmountSourceThrowsException(): void
    {
        $provider = new DefaultSaleMappingYamlProvider(self::FIXTURES_DIR.'/invalid_duplicate_amount_source.yaml');

        $this->expectException(DefaultSaleMappingConfigException::class);
        $this->expectExceptionMessageMatches('/Duplicate amount_source/');
        $provider->getAll();
    }

    public function testAmountSourceRestrictedToAnotherMarketplaceThrowsException(): void
    {
        $provider = new DefaultSaleMappingYamlProvider(self::FIXTURES_DIR.'/invalid_marketplace_restriction.yaml');

        $this->expectException(DefaultSaleMappingConfigException::class);
        $this->expectExceptionMessageMatches('/only available for marketplace/');
        $provider->getAll();
    }

    public function testUnknownAmountSourceThrowsException(): void
    {
        $provider = new DefaultSaleMappingYamlProvider(self::FIXTURES_DIR.'/invalid_unknown_amount_source.yaml');

        $this->expectException(DefaultSaleMappingConfigException::class);
        $this->expectExceptionMessageMatches('/Unknown amount_source/');
        $provider->getAll();
    }

    public function testMissingIsNegativeThrowsException(): void
    {
        $provider = new DefaultSaleMappingYamlProvider(self::FIXTURES_DIR.'/invalid_missing_is_negative.yaml');

        $this->expectException(DefaultSaleMappingConfigException::class);
        $this->expectExceptionMessageMatches('/is_negative/');
        $provider->getAll();
    }
}
