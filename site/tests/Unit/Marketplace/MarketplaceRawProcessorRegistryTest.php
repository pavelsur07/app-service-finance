<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Marketplace\Application\Processor\MarketplaceRawProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistry;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use PHPUnit\Framework\TestCase;

final class MarketplaceRawProcessorRegistryTest extends TestCase
{
    public function testReturnsFirstSupportingProcessor(): void
    {
        $target = new class implements MarketplaceRawProcessorInterface {
            public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
            {
                return $type === 'ozon' && $kind === 'sales';
            }

            public function process(string $companyId, string $rawDocId): int
            {
                return 5;
            }

            public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows, ?string $rawDocId = null): void {}
        };

        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $registry = new MarketplaceRawProcessorRegistry([
            new class implements MarketplaceRawProcessorInterface {
                public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
                {
                    return false;
                }

                public function process(string $companyId, string $rawDocId): int
                {
                    return 0;
                }

                public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows, ?string $rawDocId = null): void {}
            },
            $target,
        ], $repo);

        self::assertSame($target, $registry->get('ozon', MarketplaceType::OZON, 'sales'));
    }

    public function testThrowsWhenNoProcessorFound(): void
    {
        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $registry = new MarketplaceRawProcessorRegistry([], $repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Processor not found for type "ozon"');

        $registry->get('ozon', MarketplaceType::OZON, 'returns');
    }
}
