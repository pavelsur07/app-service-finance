<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\SystemCounterpartyRepository;

final class SystemCounterpartyResolver
{
    /** @var array<string, string|null> */
    private array $cache = [];

    public function __construct(private readonly SystemCounterpartyRepository $repository)
    {
    }

    public function resolve(IngestSource $source): ?string
    {
        if (array_key_exists($source->value, $this->cache)) {
            return $this->cache[$source->value];
        }

        $counterparty = $this->repository->findBySource($source);
        $this->cache[$source->value] = $counterparty?->getId();

        return $this->cache[$source->value];
    }
}
