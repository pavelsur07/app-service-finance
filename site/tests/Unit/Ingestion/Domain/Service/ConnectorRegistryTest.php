<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain\Service;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PullResult;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\DTO\PushResult;
use App\Ingestion\Application\DTO\ShopDescriptor;
use App\Ingestion\Domain\Contract\SourceConnectorInterface;
use App\Ingestion\Domain\Service\ConnectorRegistry;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\ConnectorNotFoundException;
use PHPUnit\Framework\TestCase;

final class ConnectorRegistryTest extends TestCase
{
    public function testReturnsConnectorBySource(): void
    {
        $connector = $this->connector(IngestSource::OZON, ['ozon_resource']);
        $registry = new ConnectorRegistry([$connector]);

        self::assertTrue($registry->has(IngestSource::OZON, 'ozon_resource'));
        self::assertSame($connector, $registry->get(IngestSource::OZON, 'ozon_resource'));
        self::assertFalse($registry->has(IngestSource::WILDBERRIES, 'wb_resource'));
    }

    public function testMissingConnectorThrowsDomainException(): void
    {
        $registry = new ConnectorRegistry([]);

        $this->expectException(ConnectorNotFoundException::class);
        $registry->get(IngestSource::OZON, 'ozon_resource');
    }

    public function testDuplicateSourceAndResourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConnectorRegistry([
            $this->connector(IngestSource::OZON, ['ozon_resource']),
            $this->connector(IngestSource::OZON, ['ozon_resource']),
        ]);
    }

    public function testSameSourceWithDifferentResourcesIsAllowed(): void
    {
        $first = $this->connector(IngestSource::OZON, ['first_resource']);
        $second = $this->connector(IngestSource::OZON, ['second_resource']);
        $registry = new ConnectorRegistry([$first, $second]);

        self::assertSame($first, $registry->get(IngestSource::OZON, 'first_resource'));
        self::assertSame($second, $registry->get(IngestSource::OZON, 'second_resource'));
    }

    /**
     * @param list<string> $resourceTypes
     */
    private function connector(IngestSource $source, array $resourceTypes): SourceConnectorInterface
    {
        return new class($source, $resourceTypes) implements SourceConnectorInterface {
            /**
             * @param list<string> $resourceTypes
             */
            public function __construct(private readonly IngestSource $source, private readonly array $resourceTypes)
            {
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
             * @return list<Capability>
             */
            public function capabilities(): array
            {
                return [Capability::CAN_PULL];
            }

            /**
             * @return list<ShopDescriptor>
             */
            public function discoverShops(string $companyId, string $connectionRef): array
            {
                return [];
            }

            public function pull(PullRequest $request): PullResult
            {
                throw new \LogicException('Not used in registry tests.');
            }

            public function push(PushRequest $request): PushResult
            {
                throw new \LogicException('Not used in registry tests.');
            }
        };
    }
}
