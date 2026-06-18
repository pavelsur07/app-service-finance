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
        $connector = $this->connector(IngestSource::OZON);
        $registry = new ConnectorRegistry([$connector]);

        self::assertTrue($registry->has(IngestSource::OZON));
        self::assertSame($connector, $registry->get(IngestSource::OZON));
        self::assertFalse($registry->has(IngestSource::WILDBERRIES));
    }

    public function testMissingConnectorThrowsDomainException(): void
    {
        $registry = new ConnectorRegistry([]);

        $this->expectException(ConnectorNotFoundException::class);
        $registry->get(IngestSource::OZON);
    }

    public function testDuplicateSourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConnectorRegistry([
            $this->connector(IngestSource::OZON),
            $this->connector(IngestSource::OZON),
        ]);
    }

    private function connector(IngestSource $source): SourceConnectorInterface
    {
        return new class($source) implements SourceConnectorInterface {
            public function __construct(private readonly IngestSource $source)
            {
            }

            public function source(): IngestSource
            {
                return $this->source;
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
