<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Marketplace\Application\Processor\MarketplaceRawProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistry;
use App\Marketplace\Enum\StagingRecordType;
use PHPUnit\Framework\TestCase;

final class MarketplaceRawProcessorRegistryTest extends TestCase
{
    public function testReturnsFirstSupportingProcessor(): void
    {
        $target = new class implements MarketplaceRawProcessorInterface {
            public function supports(string|StagingRecordType $type, string $kind = ''): bool
            {
                return $type === 'ozon' && $kind === 'sales';
            }

            public function process(string $companyId, string $rawDocId): int
            {
                return 5;
            }
        };

        $registry = new MarketplaceRawProcessorRegistry([
            new class implements MarketplaceRawProcessorInterface {
                public function supports(string|StagingRecordType $type, string $kind = ''): bool
                {
                    return false;
                }

                public function process(string $companyId, string $rawDocId): int
                {
                    return 0;
                }
            },
            $target,
        ]);

        self::assertSame($target, $registry->get('ozon', 'sales'));
    }

    public function testThrowsWhenNoProcessorFound(): void
    {
        $registry = new MarketplaceRawProcessorRegistry([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Processor not found for type "wildberries" and kind "returns"');

        $registry->get('wildberries', 'returns');
    }
}
