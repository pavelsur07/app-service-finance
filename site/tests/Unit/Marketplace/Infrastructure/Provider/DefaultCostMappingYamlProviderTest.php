<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Provider;

use App\Marketplace\Application\Exception\DefaultCostMappingConfigException;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Provider\DefaultCostMappingYamlProvider;
use PHPUnit\Framework\TestCase;

final class DefaultCostMappingYamlProviderTest extends TestCase
{
    private const DEFAULT_CONFIG_PATH = __DIR__ . '/../../../../../config/marketplace/default_cost_mapping.yaml';
    private const FIXTURES_DIR = __DIR__ . '/../../../../Fixtures/Marketplace/Provider';

    public function testItReadsDefaultConfigSuccessfully(): void
    {
        $provider = new DefaultCostMappingYamlProvider(self::DEFAULT_CONFIG_PATH);

        $all = $provider->getAll();

        self::assertArrayHasKey(MarketplaceType::OZON->value, $all);
    }

    public function testItReturnsOzonRules(): void
    {
        $provider = new DefaultCostMappingYamlProvider(self::DEFAULT_CONFIG_PATH);

        $ruleSet = $provider->getForMarketplace(MarketplaceType::OZON);

        self::assertGreaterThan(0, $ruleSet->count());
        self::assertSame(MarketplaceType::OZON, $ruleSet->getMarketplace());

        $commission = $ruleSet->getByCostCode('ozon_sale_commission');
        self::assertNotNull($commission);
        self::assertSame('COGS_MP_COMMISSION', $commission->getPlCode());

        $acquiring = $ruleSet->getByCostCode('ozon_acquiring');
        self::assertNotNull($acquiring);
        self::assertSame('COGS_ACQUIRING', $acquiring->getPlCode());
    }

    public function testMarketplaceWithoutRulesReturnsEmptyRuleSet(): void
    {
        $provider = new DefaultCostMappingYamlProvider(self::DEFAULT_CONFIG_PATH);

        $ruleSet = $provider->getForMarketplace(MarketplaceType::YANDEX_MARKET);

        self::assertSame(0, $ruleSet->count());
        self::assertSame(MarketplaceType::YANDEX_MARKET, $ruleSet->getMarketplace());
    }

    public function testDuplicateCostCodeThrowsException(): void
    {
        $provider = new DefaultCostMappingYamlProvider(self::FIXTURES_DIR . '/invalid_duplicate_cost_code.yaml');

        $this->expectException(DefaultCostMappingConfigException::class);
        $this->expectExceptionMessage('Duplicate cost_code');

        $provider->getAll();
    }

    public function testInvalidConfidenceThrowsException(): void
    {
        $provider = new DefaultCostMappingYamlProvider(self::FIXTURES_DIR . '/invalid_confidence.yaml');

        $this->expectException(DefaultCostMappingConfigException::class);
        $this->expectExceptionMessage('invalid confidence');

        $provider->getAll();
    }

    public function testMissingRequiredFieldThrowsException(): void
    {
        $provider = new DefaultCostMappingYamlProvider(self::FIXTURES_DIR . '/missing_required_field.yaml');

        $this->expectException(DefaultCostMappingConfigException::class);
        $this->expectExceptionMessage('pl_code');

        $provider->getAll();
    }

    public function testDefaultsAreAppliedForOptionalFields(): void
    {
        $provider = new DefaultCostMappingYamlProvider(self::FIXTURES_DIR . '/defaults_optional_fields.yaml');

        $ruleSet = $provider->getForMarketplace(MarketplaceType::OZON);
        $rule = $ruleSet->getByCostCode('ozon_sale_commission');

        self::assertNotNull($rule);
        self::assertTrue($rule->isNegative());
        self::assertSame('high', $rule->getConfidence());
    }
}
