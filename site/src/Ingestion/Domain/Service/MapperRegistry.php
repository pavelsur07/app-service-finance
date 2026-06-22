<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Service;

use App\Ingestion\Domain\Contract\SourceMapperInterface;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\MapperNotFoundException;

final class MapperRegistry
{
    /**
     * @var array<string, array<string, SourceMapperInterface>>
     */
    private array $mappers = [];

    /**
     * @param iterable<SourceMapperInterface> $mappers
     */
    public function __construct(iterable $mappers)
    {
        foreach ($mappers as $mapper) {
            $source = $mapper->source()->value;
            foreach ($mapper->resourceTypes() as $resourceType) {
                if (isset($this->mappers[$source][$resourceType])) {
                    throw new \InvalidArgumentException(sprintf('Duplicate ingestion mapper for source "%s" and resource "%s".', $source, $resourceType));
                }

                $this->mappers[$source][$resourceType] = $mapper;
            }
        }
    }

    public function get(IngestSource $source, string $resourceType): SourceMapperInterface
    {
        return $this->mappers[$source->value][$resourceType]
            ?? throw new MapperNotFoundException(sprintf('Mapper for source "%s" and resource "%s" was not found.', $source->value, $resourceType));
    }

    public function has(IngestSource $source, string $resourceType): bool
    {
        return isset($this->mappers[$source->value][$resourceType]);
    }
}
