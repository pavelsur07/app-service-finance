<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO\Internal;

/** Элемент `connections[]` контракта `marketplace-connections` v1. */
final readonly class ConnectionConfig
{
    /**
     * @param array{client_id: string, api_key: string}|null $seller
     * @param array{client_id: string, client_secret: string}|null $performance
     * @param list<string> $resourceTypes
     */
    public function __construct(
        public string $connectionRef,
        public string $companyId,
        public string $provider,
        public \DateTimeImmutable $updatedAt,
        public ?array $seller,
        public ?array $performance,
        public array $resourceTypes,
    ) {
    }

    /**
     * @return array{connection_ref: string, company_id: string, provider: string, updated_at: string, credentials: array{seller: array{client_id: string, api_key: string}|null, performance: array{client_id: string, client_secret: string}|null}, resource_types: list<string>}
     */
    public function toArray(): array
    {
        return [
            'connection_ref' => $this->connectionRef,
            'company_id' => $this->companyId,
            'provider' => $this->provider,
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
            'credentials' => [
                'seller' => $this->seller,
                'performance' => $this->performance,
            ],
            'resource_types' => $this->resourceTypes,
        ];
    }
}
