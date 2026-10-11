<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\DefaultCostMappingSiblingResolver;
use PHPUnit\Framework\TestCase;

final class DefaultCostMappingSiblingResolverTest extends TestCase
{
    private const array TEMPLATE = [
        'ozon' => [
            'ozon_logistic_return' => 'COGS_RETURNS_DELIVERY',
            'ozon_return_pvz' => 'COGS_RETURNS_DELIVERY',
            'ozon_return_from_stock' => 'COGS_RETURNS_DELIVERY',
            'ozon_logistic_inbound' => 'OPEX_WH_RECEIVING',
            'ozon_supply_surplus' => 'OPEX_WH_RECEIVING',
            'ozon_crossdocking' => 'OPEX_WH_RECEIVING',
            'ozon_disposal' => 'OPEX_WH_MP_DEDUCTIONS',
        ],
        'wildberries' => [
            'logistics_return' => 'COGS_RETURNS_DELIVERY',
            'pvz_processing' => 'COGS_RETURNS_DELIVERY',
            'product_processing' => 'OPEX_WH_RECEIVING',
            'storage' => 'OPEX_WH_STORAGE',
        ],
    ];

    public function testUnanimousSamplesGiveTheirLine(): void
    {
        $resolved = $this->resolve('ozon', ['ozon' => [
            'ozon_logistic_return' => 'returns-line',
            'ozon_return_pvz' => 'returns-line',
        ]]);

        self::assertSame(['COGS_RETURNS_DELIVERY' => ['pl_category_id' => 'returns-line', 'cross_marketplace' => false]], $resolved);
    }

    public function testConflictingSamplesGiveNoLine(): void
    {
        $resolved = $this->resolve('ozon', ['ozon' => [
            'ozon_logistic_inbound' => 'storage-line',
            'ozon_supply_surplus' => 'receiving-line',
        ]]);

        self::assertSame([], $resolved);
    }

    public function testNoSamplesGiveNoLine(): void
    {
        self::assertSame([], $this->resolve('ozon', []));
    }

    public function testSampleOfAnotherTemplateLineIsNotUsed(): void
    {
        $resolved = $this->resolve('ozon', ['ozon' => [
            'ozon_disposal' => 'deductions-line',
            // Код вне шаблона — не образец ни для какой статьи.
            'ozon_unknown_126' => 'returns-line',
        ]]);

        self::assertSame(['OPEX_WH_MP_DEDUCTIONS' => ['pl_category_id' => 'deductions-line', 'cross_marketplace' => false]], $resolved);
    }

    public function testOtherMarketplaceSamplesAreUsedWhenOwnMarketplaceHasNone(): void
    {
        $resolved = $this->resolve('wildberries', [
            'ozon' => [
                'ozon_logistic_return' => 'returns-line',
                'ozon_return_from_stock' => 'returns-line',
            ],
            'wildberries' => ['storage' => 'storage-line'],
        ]);

        self::assertSame([
            'OPEX_WH_STORAGE' => ['pl_category_id' => 'storage-line', 'cross_marketplace' => false],
            'COGS_RETURNS_DELIVERY' => ['pl_category_id' => 'returns-line', 'cross_marketplace' => true],
        ], $resolved);
    }

    public function testOwnMarketplaceSamplesWinOverOtherMarketplace(): void
    {
        $resolved = $this->resolve('wildberries', [
            'ozon' => ['ozon_logistic_return' => 'ozon-returns-line'],
            'wildberries' => ['pvz_processing' => 'wb-returns-line'],
        ]);

        self::assertSame(['COGS_RETURNS_DELIVERY' => ['pl_category_id' => 'wb-returns-line', 'cross_marketplace' => false]], $resolved);
    }

    public function testOwnMarketplaceConflictIsNotOverriddenByOtherMarketplace(): void
    {
        $resolved = $this->resolve('ozon', [
            'ozon' => [
                'ozon_logistic_inbound' => 'storage-line',
                'ozon_supply_surplus' => 'receiving-line',
            ],
            'wildberries' => ['product_processing' => 'storage-line'],
        ]);

        self::assertSame([], $resolved);
    }

    public function testOwnMarketplaceExclusionIsNotOverriddenByOtherMarketplace(): void
    {
        // WB-возвраты компания исключила из ОПиУ (или отнесла в группу) — это решение, не пробел.
        $resolved = $this->resolve('wildberries', [
            'ozon' => ['ozon_logistic_return' => 'returns-line'],
            'wildberries' => ['logistics_return' => null],
        ]);

        self::assertSame([], $resolved);
    }

    public function testOwnMarketplaceExclusionDoesNotSpoilOwnSample(): void
    {
        $resolved = $this->resolve('wildberries', [
            'wildberries' => ['logistics_return' => null, 'pvz_processing' => 'wb-returns-line'],
        ]);

        self::assertSame(['COGS_RETURNS_DELIVERY' => ['pl_category_id' => 'wb-returns-line', 'cross_marketplace' => false]], $resolved);
    }

    public function testConflictingOtherMarketplaceSamplesGiveNoLine(): void
    {
        $resolved = $this->resolve('wildberries', ['ozon' => [
            'ozon_logistic_return' => 'returns-line',
            'ozon_return_pvz' => 'delivery-line',
        ]]);

        self::assertSame([], $resolved);
    }

    public function testSameCodeInAnotherMarketplaceIsMatchedByThatMarketplaceTemplate(): void
    {
        // У WB код storage — OPEX_WH_STORAGE; у Ozon такого кода в шаблоне нет — не образец.
        $resolved = $this->resolve('wildberries', ['ozon' => ['storage' => 'storage-line']]);

        self::assertSame([], $resolved);
    }

    /**
     * @param array<string, array<string, string|null>> $companyMapping
     *
     * @return array<string, array{pl_category_id: string, cross_marketplace: bool}>
     */
    private function resolve(string $marketplace, array $companyMapping): array
    {
        return (new DefaultCostMappingSiblingResolver())->resolve($marketplace, self::TEMPLATE, $companyMapping);
    }
}
