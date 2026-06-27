<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Service;

use App\Ingestion\Domain\Contract\SourceConnectorInterface;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\ConnectorNotFoundException;

final class ConnectorRegistry
{
    /**
     * @var array<string, array<string, SourceConnectorInterface>>
     */
    private array $connectors = [];

    /**
     * @param iterable<SourceConnectorInterface> $connectors
     */
    public function __construct(iterable $connectors)
    {
        foreach ($connectors as $connector) {
            $source = $connector->source()->value;
            foreach ($connector->resourceTypes() as $resourceType) {
                if (isset($this->connectors[$source][$resourceType])) {
                    throw new \InvalidArgumentException(sprintf('Duplicate ingestion connector for source "%s" and resource "%s".', $source, $resourceType));
                }

                $this->connectors[$source][$resourceType] = $connector;
            }
        }
    }

    public function get(IngestSource $source, string $resourceType): SourceConnectorInterface
    {
        return $this->connectors[$source->value][$resourceType]
            ?? throw new ConnectorNotFoundException(sprintf('Connector for source "%s" and resource "%s" was not found.', $source->value, $resourceType));
    }

    public function has(IngestSource $source, string $resourceType): bool
    {
        return isset($this->connectors[$source->value][$resourceType]);
    }
}
