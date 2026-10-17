<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO\Internal;

/** Ответ `GET /internal/marketplace-connections` — контракт `marketplace-connections` v1. */
final readonly class MarketplaceConnectionsSnapshot
{
    public const SCHEMA_VERSION = '1';

    /**
     * @param list<ConnectionConfig> $connections
     */
    public function __construct(
        public \DateTimeImmutable $generatedAt,
        public array $connections,
    ) {
    }

    /**
     * @return array{schema_version: string, generated_at: string, connections: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $this->generatedAt->format(\DateTimeInterface::ATOM),
            'connections' => array_map(static fn (ConnectionConfig $c): array => $c->toArray(), $this->connections),
        ];
    }
}
