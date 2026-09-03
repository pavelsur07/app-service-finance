<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Service;

use App\Ingestion\Domain\Contract\OrderMapperInterface;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\MapperNotFoundException;

/**
 * Реестр мапперов заказов.
 *
 * Он же — предикат ветвления нормализации: заказный ресурс узнаётся по наличию
 * маппера, а не по списку строк в условии. Список пришлось бы поддерживать в
 * нескольких местах, и копии рано или поздно разошлись бы.
 */
final class OrderMapperRegistry
{
    /**
     * @var array<string, array<string, OrderMapperInterface>>
     */
    private array $mappers = [];

    /**
     * @param iterable<OrderMapperInterface> $mappers
     */
    public function __construct(iterable $mappers)
    {
        foreach ($mappers as $mapper) {
            $source = $mapper->source()->value;
            foreach ($mapper->resourceTypes() as $resourceType) {
                if (isset($this->mappers[$source][$resourceType])) {
                    throw new \InvalidArgumentException(sprintf('Duplicate ingestion order mapper for source "%s" and resource "%s".', $source, $resourceType));
                }

                $this->mappers[$source][$resourceType] = $mapper;
            }
        }
    }

    public function get(IngestSource $source, string $resourceType): OrderMapperInterface
    {
        return $this->mappers[$source->value][$resourceType]
            ?? throw new MapperNotFoundException(sprintf('Order mapper for source "%s" and resource "%s" was not found.', $source->value, $resourceType));
    }

    public function has(IngestSource $source, string $resourceType): bool
    {
        return isset($this->mappers[$source->value][$resourceType]);
    }

    /**
     * @return list<string> ключи вида "source:resourceType" — для проверки, что
     *                      реестры заказов и финансов не пересекаются
     */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->mappers as $source => $byResource) {
            foreach (array_keys($byResource) as $resourceType) {
                $keys[] = $source.':'.$resourceType;
            }
        }

        return $keys;
    }
}
