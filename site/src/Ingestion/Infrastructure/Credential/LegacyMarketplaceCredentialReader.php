<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Credential;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class LegacyMarketplaceCredentialReader
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{api_key: string, client_id: ?string}|null
     */
    public function read(string $companyId, string $connectionRef): ?array
    {
        Assert::uuid($companyId);

        if (Uuid::isValid($connectionRef)) {
            return $this->readByConnectionId($companyId, $connectionRef);
        }

        $parsedRef = $this->parseMarketplaceRef($connectionRef);
        if (null === $parsedRef) {
            return null;
        }

        [$marketplace, $connectionType] = $parsedRef;

        return $this->readByMarketplace($companyId, $marketplace, $connectionType);
    }

    /**
     * @return array{api_key: string, client_id: ?string}|null
     */
    private function readByConnectionId(string $companyId, string $connectionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT api_key, client_id
                FROM marketplace_connections
                WHERE id = :connection_id
                  AND company_id = :company_id
                  AND is_active = true
                LIMIT 1
            SQL,
            [
                'connection_id' => $connectionId,
                'company_id' => $companyId,
            ],
        );

        return $this->rowToPayload($row);
    }

    /**
     * @return array{api_key: string, client_id: ?string}|null
     */
    private function readByMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType,
    ): ?array {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT api_key, client_id
                FROM marketplace_connections
                WHERE company_id = :company_id
                  AND marketplace = :marketplace
                  AND connection_type = :connection_type
                  AND is_active = true
                LIMIT 1
            SQL,
            [
                'company_id' => $companyId,
                'marketplace' => $marketplace->value,
                'connection_type' => $connectionType->value,
            ],
        );

        return $this->rowToPayload($row);
    }

    /**
     * @return array{0: MarketplaceType, 1: MarketplaceConnectionType}|null
     */
    private function parseMarketplaceRef(string $connectionRef): ?array
    {
        $parts = explode(':', $connectionRef);
        if (3 === count($parts) && 'marketplace' === $parts[0]) {
            $parts = [$parts[1], $parts[2]];
        }

        if (2 !== count($parts)) {
            return null;
        }

        $marketplace = MarketplaceType::tryFrom($parts[0]);
        $connectionType = MarketplaceConnectionType::tryFrom($parts[1]);

        if (null === $marketplace || null === $connectionType) {
            return null;
        }

        return [$marketplace, $connectionType];
    }

    /**
     * @param array<string, mixed>|false $row
     *
     * @return array{api_key: string, client_id: ?string}|null
     */
    private function rowToPayload(array|false $row): ?array
    {
        if (false === $row) {
            return null;
        }

        return [
            'api_key' => (string) $row['api_key'],
            'client_id' => null !== $row['client_id'] ? (string) $row['client_id'] : null,
        ];
    }
}
