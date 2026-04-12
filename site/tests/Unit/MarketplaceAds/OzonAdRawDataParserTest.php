<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdRawDataParser;
use PHPUnit\Framework\TestCase;

final class OzonAdRawDataParserTest extends TestCase
{
    private OzonAdRawDataParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OzonAdRawDataParser();
    }

    public function testSupportsOzonMarketplace(): void
    {
        self::assertTrue($this->parser->supports(MarketplaceType::OZON->value));
        self::assertFalse($this->parser->supports(MarketplaceType::WILDBERRIES->value));
        self::assertFalse($this->parser->supports('unknown'));
    }

    public function testParsesSingleRow(): void
    {
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '123', 'campaign_name' => 'Кампания 1', 'sku' => '456',
                 'spend' => 150.50, 'views' => 1000, 'clicks' => 50],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        self::assertInstanceOf(AdRawEntry::class, $result[0]);
        self::assertSame('123', $result[0]->campaignId);
        self::assertSame('Кампания 1', $result[0]->campaignName);
        self::assertSame('456', $result[0]->parentSku);
        self::assertSame('150.50', $result[0]->cost);
        self::assertSame(1000, $result[0]->impressions);
        self::assertSame(50, $result[0]->clicks);
    }

    public function testAggregatesDuplicateCampaignSkuPairs(): void
    {
        // Один SKU (456) в одной кампании (123) встречается дважды — разные объявления/группы.
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '123', 'campaign_name' => 'К1', 'sku' => '456',
                 'spend' => 100.25, 'views' => 500, 'clicks' => 20],
                ['campaign_id' => '123', 'campaign_name' => 'К1', 'sku' => '456',
                 'spend' => 50.75, 'views' => 300, 'clicks' => 15],
                ['campaign_id' => '123', 'campaign_name' => 'К1', 'sku' => '999',
                 'spend' => 25.00, 'views' => 100, 'clicks' => 5],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(2, $result);

        // Первая запись — агрегированная (123 + 456)
        self::assertSame('123', $result[0]->campaignId);
        self::assertSame('456', $result[0]->parentSku);
        self::assertSame('151.00', $result[0]->cost);
        self::assertSame(800, $result[0]->impressions);
        self::assertSame(35, $result[0]->clicks);

        // Вторая запись — отдельный SKU (123 + 999)
        self::assertSame('123', $result[1]->campaignId);
        self::assertSame('999', $result[1]->parentSku);
        self::assertSame('25.00', $result[1]->cost);
    }

    public function testSeparatesDifferentCampaigns(): void
    {
        // Одинаковый SKU в разных кампаниях — НЕ агрегировать.
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '111', 'campaign_name' => 'A', 'sku' => '456',
                 'spend' => 10.00, 'views' => 100, 'clicks' => 1],
                ['campaign_id' => '222', 'campaign_name' => 'B', 'sku' => '456',
                 'spend' => 20.00, 'views' => 200, 'clicks' => 2],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(2, $result);
        self::assertSame('111', $result[0]->campaignId);
        self::assertSame('222', $result[1]->campaignId);
    }

    public function testEmptyRowsReturnsEmptyArray(): void
    {
        self::assertSame([], $this->parser->parse('{"rows": []}'));
        self::assertSame([], $this->parser->parse('{}'));
    }

    public function testRoundingOfFractionalSpend(): void
    {
        // float → string через number_format с 2 знаками
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '1', 'campaign_name' => 'A', 'sku' => 'X',
                 'spend' => 1.005, 'views' => 1, 'clicks' => 0],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        // Важно: результат — string, а не float
        self::assertIsString($result[0]->cost);
        self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $result[0]->cost);
    }

    public function testSkipsRowsWithMissingRequiredFields(): void
    {
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '1', 'campaign_name' => 'A', 'sku' => 'X',
                 'spend' => 10.00, 'views' => 100, 'clicks' => 5],
                ['campaign_name' => 'no id', 'sku' => 'Y',
                 'spend' => 5.00, 'views' => 50, 'clicks' => 1],
                ['campaign_id' => '2', 'campaign_name' => 'no sku',
                 'spend' => 5.00, 'views' => 50, 'clicks' => 1],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        self::assertSame('1', $result[0]->campaignId);
    }

    public function testThrowsOnMalformedJson(): void
    {
        $this->expectException(\JsonException::class);
        $this->parser->parse('not a json');
    }
}
