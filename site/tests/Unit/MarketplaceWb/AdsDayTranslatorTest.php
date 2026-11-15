<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceWb;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceWb\Domain\AdsDayTranslator;
use App\MarketplaceWb\Domain\WbComponent;
use PHPUnit\Framework\TestCase;

final class AdsDayTranslatorTest extends TestCase
{
    public function testCampaignSpendIsSplitByNmSpendWithLargestRemainder(): void
    {
        $components = AdsDayTranslator::translate('wb:ads.daily:7', [
            'advertId' => '7',
            'expenses' => [['advertId' => 7, 'updSum' => 60], ['advertId' => 7, 'updSum' => '40.01']],
            'statistics' => ['advertId' => 7, 'days' => [['date' => '2026-11-10', 'apps' => [
                ['nms' => [['nmId' => 111, 'sum' => 1], ['nmId' => 222, 'sum' => 2]]],
                ['nms' => [['nmId' => 111, 'sum' => 0.5], ['nmId' => 0, 'sum' => 9]]],
            ]]]],
        ]);

        self::assertSame(['nm111' => -4286, 'nm222' => -5715], $this->byNm($components), '100,01 ₽ по весам 1,5 : 2, Σ не меняется');
        foreach ($components as $component) {
            self::assertSame([OperationKind::ADS_CPC, AdsDayTranslator::SOURCE_TYPE, 'wb:ads.daily:7', null], [$component->kind, $component->sourceType, $component->sourceKey, $component->size]);
        }
    }

    public function testCampaignWithoutStatisticsStaysWithoutSku(): void
    {
        $components = AdsDayTranslator::translate('wb:ads.daily:8', ['advertId' => '8', 'expenses' => [['updSum' => 12.5]], 'statistics' => null]);

        self::assertSame(['nm' => -1250], $this->byNm($components));
    }

    public function testNoSpendGivesNothing(): void
    {
        self::assertSame([], AdsDayTranslator::translate('wb:ads.daily:9', ['advertId' => '9', 'expenses' => [['updSum' => 0], ['updSum' => 'x']], 'statistics' => null]));
    }

    /**
     * @param list<WbComponent> $components
     *
     * @return array<string, int>
     */
    private function byNm(array $components): array
    {
        $out = [];
        foreach ($components as $component) {
            $out['nm'.($component->nmId ?? '')] = $component->amount->amountMinor();
        }

        return $out;
    }
}
