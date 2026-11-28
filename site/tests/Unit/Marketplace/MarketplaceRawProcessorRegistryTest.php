<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Marketplace\Application\Processor\MarketplaceRawProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistry;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use PHPUnit\Framework\TestCase;

final class MarketplaceRawProcessorRegistryTest extends TestCase
{
    public function testReturnsFirstSupportingProcessor(): void
    {
        $target = new class implements MarketplaceRawProcessorInterface {
            public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = '', ?MarketplaceRawFormat $format = null): bool
            {
                return 'ozon' === $type && 'sales' === $kind;
            }

            public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows, ?string $rawDocId = null): void
            {
            }
        };

        $registry = new MarketplaceRawProcessorRegistry([
            new class implements MarketplaceRawProcessorInterface {
                public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = '', ?MarketplaceRawFormat $format = null): bool
                {
                    return false;
                }

                public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows, ?string $rawDocId = null): void
                {
                }
            },
            $target,
        ]);

        self::assertSame($target, $registry->get('ozon', MarketplaceType::OZON, 'sales'));
    }

    public function testWholeDocumentCannotBeSentToABatchOnlyProcessor(): void
    {
        $processor = $this->createStub(MarketplaceRawProcessorInterface::class);
        $processor->method('supports')->willReturn(true);
        $registry = new MarketplaceRawProcessorRegistry([$processor]);

        $this->expectException(\LogicException::class);
        $registry->getForDocument(StagingRecordType::SALE, MarketplaceType::OZON, 'sales');
    }

    public function testThrowsWhenNoProcessorFound(): void
    {
        $registry = new MarketplaceRawProcessorRegistry([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Processor not found for type "ozon"');

        $registry->get('ozon', MarketplaceType::OZON, 'returns');
    }
}
