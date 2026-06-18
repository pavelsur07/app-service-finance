<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Service;

use App\Ingestion\Domain\Contract\SourceConnectorInterface;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\ConnectorNotFoundException;

final class ConnectorRegistry
{
    /**
     * @var array<string, SourceConnectorInterface>
     */
    private array $connectors = [];

    /**
     * @param iterable<SourceConnectorInterface> $connectors
     */
    public function __construct(iterable $connectors)
    {
        foreach ($connectors as $connector) {
            $source = $connector->source()->value;
            if (isset($this->connectors[$source])) {
                throw new \InvalidArgumentException(sprintf('Duplicate ingestion connector for source "%s".', $source));
            }

            $this->connectors[$source] = $connector;
        }
    }

    public function get(IngestSource $source): SourceConnectorInterface
    {
        return $this->connectors[$source->value]
            ?? throw new ConnectorNotFoundException(sprintf('Connector for source "%s" was not found.', $source->value));
    }

    public function has(IngestSource $source): bool
    {
        return isset($this->connectors[$source->value]);
    }
}
