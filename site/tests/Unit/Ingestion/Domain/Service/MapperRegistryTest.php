<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain\Service;

use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Domain\Contract\SourceMapperInterface;
use App\Ingestion\Domain\Service\MapperRegistry;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\MapperNotFoundException;
use PHPUnit\Framework\TestCase;

final class MapperRegistryTest extends TestCase
{
    public function testReturnsMapperBySourceAndResource(): void
    {
        $mapper = $this->mapper(IngestSource::OZON, ['sales', 'returns']);
        $registry = new MapperRegistry([$mapper]);

        self::assertSame($mapper, $registry->get(IngestSource::OZON, 'sales'));
        self::assertSame($mapper, $registry->get(IngestSource::OZON, 'returns'));
    }

    public function testMissingMapperThrowsDomainException(): void
    {
        $registry = new MapperRegistry([]);

        $this->expectException(MapperNotFoundException::class);
        $registry->get(IngestSource::OZON, 'sales');
    }

    public function testDuplicateSourceResourcePairIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MapperRegistry([
            $this->mapper(IngestSource::OZON, ['sales']),
            $this->mapper(IngestSource::OZON, ['sales']),
        ]);
    }

    /**
     * @param list<string> $resourceTypes
     */
    private function mapper(IngestSource $source, array $resourceTypes): SourceMapperInterface
    {
        return new class($source, $resourceTypes) implements SourceMapperInterface {
            /**
             * @param list<string> $resourceTypes
             */
            public function __construct(
                private readonly IngestSource $source,
                private readonly array $resourceTypes,
            ) {
            }

            public function source(): IngestSource
            {
                return $this->source;
            }

            /**
             * @return list<string>
             */
            public function resourceTypes(): array
            {
                return $this->resourceTypes;
            }

            /**
             * @param iterable<array<string, mixed>> $rows
             *
             * @return list<MappedTransaction>
             */
            public function map(IngestRawRecord $rawRecord, iterable $rows): array
            {
                return [];
            }

            /**
             * @param iterable<array<string, mixed>> $rows
             *
             * @return list<MappedControlSum>
             */
            public function controlSum(iterable $rows): array
            {
                return [];
            }
        };
    }
}
