<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesJsonDecoder;
use PHPUnit\Framework\TestCase;

final class WildberriesJsonDecoderTest extends TestCase
{
    public function testPreservesAllJsonNumberLexemesAsStrings(): void
    {
        $decoded = (new WildberriesJsonDecoder())->decodeObjectList(
            '[{"integer":123,"decimal":0.0100,"negative":-2.5,"exponent":1.2e+3,"text":"45.60","nested":[7]}]',
        );

        self::assertSame('123', $decoded[0]['integer']);
        self::assertSame('0.0100', $decoded[0]['decimal']);
        self::assertSame('-2.5', $decoded[0]['negative']);
        self::assertSame('1.2e+3', $decoded[0]['exponent']);
        self::assertSame('45.60', $decoded[0]['text']);
        self::assertSame(['7'], $decoded[0]['nested']);
    }

    public function testDoesNotTreatDigitsInsideEscapedStringsAsNumbers(): void
    {
        $decoded = (new WildberriesJsonDecoder())->decodeObjectList(
            '[{"value":"quote: \\"123.40\\" and slash: \\\\9","number":123.40}]',
        );

        self::assertSame('quote: "123.40" and slash: \\9', $decoded[0]['value']);
        self::assertSame('123.40', $decoded[0]['number']);
    }

    public function testRejectsInvalidJson(): void
    {
        foreach (['[{"value":1.]', '[{"value":-}]', '[{"value":1e}]'] as $json) {
            try {
                (new WildberriesJsonDecoder())->decodeObjectList($json);
                self::fail('Expected invalid JSON number to be rejected.');
            } catch (\JsonException|\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsNonListAndScalarListItems(): void
    {
        $decoder = new WildberriesJsonDecoder();

        foreach (['{"value":1}', '[1]'] as $json) {
            try {
                $decoder->decodeObjectList($json);
                self::fail('Expected invalid response shape to be rejected.');
            } catch (\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDecodesDenseNestedFullStatsShapeWithoutLosingNumbers(): void
    {
        $campaigns = [];
        for ($campaign = 1; $campaign <= 50; ++$campaign) {
            $nms = [];
            for ($sku = 1; $sku <= 100; ++$sku) {
                $nms[] = sprintf(
                    '{"nmId":%d,"sum":%d.0100,"views":%d,"clicks":%d}',
                    $campaign * 1000 + $sku,
                    $sku,
                    $sku * 10,
                    $sku,
                );
            }
            $campaigns[] = sprintf(
                '{"advertId":%d,"days":[{"date":"2026-07-20","apps":[{"nms":[%s]}]}]}',
                $campaign,
                implode(',', $nms),
            );
        }

        $decoded = (new WildberriesJsonDecoder())->decodeObjectList('['.implode(',', $campaigns).']');

        self::assertCount(50, $decoded);
        self::assertSame('1', $decoded[0]['advertId']);
        self::assertSame('1001', $decoded[0]['days'][0]['apps'][0]['nms'][0]['nmId']);
        self::assertSame('100.0100', $decoded[49]['days'][0]['apps'][0]['nms'][99]['sum']);
    }
}
