<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\DefaultCostMappingSiblingResolver;
use PHPUnit\Framework\TestCase;

final class DefaultCostMappingSiblingResolverTest extends TestCase
{
    private const array TEMPLATE = [
        'ozon_logistic_return' => 'COGS_RETURNS_DELIVERY',
        'ozon_return_pvz' => 'COGS_RETURNS_DELIVERY',
        'ozon_return_from_stock' => 'COGS_RETURNS_DELIVERY',
        'ozon_logistic_inbound' => 'OPEX_WH_RECEIVING',
        'ozon_supply_surplus' => 'OPEX_WH_RECEIVING',
        'ozon_crossdocking' => 'OPEX_WH_RECEIVING',
        'ozon_disposal' => 'OPEX_WH_MP_DEDUCTIONS',
    ];

    public function testUnanimousSamplesGiveTheirLine(): void
    {
        $resolved = (new DefaultCostMappingSiblingResolver())->resolve(self::TEMPLATE, [
            'ozon_logistic_return' => 'returns-line',
            'ozon_return_pvz' => 'returns-line',
        ]);

        self::assertSame(['COGS_RETURNS_DELIVERY' => 'returns-line'], $resolved);
    }

    public function testConflictingSamplesGiveNoLine(): void
    {
        $resolved = (new DefaultCostMappingSiblingResolver())->resolve(self::TEMPLATE, [
            'ozon_logistic_inbound' => 'storage-line',
            'ozon_supply_surplus' => 'receiving-line',
        ]);

        self::assertSame([], $resolved);
    }

    public function testNoSamplesGiveNoLine(): void
    {
        self::assertSame([], (new DefaultCostMappingSiblingResolver())->resolve(self::TEMPLATE, []));
    }

    public function testSampleOfAnotherTemplateLineIsNotUsed(): void
    {
        $resolved = (new DefaultCostMappingSiblingResolver())->resolve(self::TEMPLATE, [
            'ozon_disposal' => 'deductions-line',
            // Код вне шаблона — не образец ни для какой статьи.
            'ozon_unknown_126' => 'returns-line',
        ]);

        self::assertSame(['OPEX_WH_MP_DEDUCTIONS' => 'deductions-line'], $resolved);
        self::assertArrayNotHasKey('COGS_RETURNS_DELIVERY', $resolved);
    }
}
