<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\MarketplaceBarcodeCatalogService;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceBarcodeCatalogRepository;
use PHPUnit\Framework\TestCase;

final class MarketplaceBarcodeCatalogServiceTest extends TestCase
{
    /**
     * Регрессия: строки финансового API WB приходят в camelCase (nmId/sku/techSize),
     * и справочник barcode→size должен наполняться из них так же, как из snake_case.
     */
    public function testFillFromWbRowsAcceptsCamelCaseFinanceApiRows(): void
    {
        $captured = null;
        $repository = $this->createMock(MarketplaceBarcodeCatalogRepository::class);
        $repository->expects(self::once())
            ->method('upsertBatch')
            ->willReturnCallback(static function (array $rows) use (&$captured): void {
                $captured = $rows;
            });

        (new MarketplaceBarcodeCatalogService($repository))->fillFromWbRows('company-1', [
            ['nmId' => '123456', 'sku' => '2000000000017', 'techSize' => 'M'],
            ['nm_id' => '654321', 'barcode' => '2000000000024', 'ts_name' => 'L'],
            ['nmId' => '0', 'sku' => '2000000000031', 'techSize' => 'S'],
            ['nmId' => '777', 'sku' => '2000000000048', 'techSize' => ''],
        ]);

        self::assertNotNull($captured);
        self::assertCount(2, $captured);
        self::assertSame(
            [['123456', '2000000000017', 'M'], ['654321', '2000000000024', 'L']],
            array_map(static fn (array $row): array => [$row['externalId'], $row['barcode'], $row['size']], $captured),
        );
        foreach ($captured as $row) {
            self::assertSame('company-1', $row['companyId']);
            self::assertSame(MarketplaceType::WILDBERRIES, $row['marketplace']);
        }
    }

    public function testFillFromWbRowsSkipsUpsertWhenNothingIsUsable(): void
    {
        $repository = $this->createMock(MarketplaceBarcodeCatalogRepository::class);
        $repository->expects(self::never())->method('upsertBatch');

        (new MarketplaceBarcodeCatalogService($repository))->fillFromWbRows('company-1', [
            ['nmId' => '123456', 'sku' => '', 'techSize' => 'M'],
        ]);
    }
}
