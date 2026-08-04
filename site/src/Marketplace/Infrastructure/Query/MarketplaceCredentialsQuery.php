<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use Doctrine\DBAL\Connection;

final readonly class MarketplaceCredentialsQuery
{
    public function __construct(
        private Connection $connection,
        private ConnectionApiKeyCodec $connectionApiKeyCodec,
    ) {
    }

    /**
     * @return array{api_key: string, client_id: ?string}|null
     */
    public function getCredentials(
        string $companyId,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType = MarketplaceConnectionType::SELLER,
        ?string $connectionRef = null,
    ): ?array {
        $sql = <<<'SQL'
            SELECT mc.api_key, mc.api_key_encrypted, mc.api_key_key_version, mc.client_id
            FROM marketplace_connections mc
            WHERE mc.company_id = :company_id
              AND mc.marketplace = :marketplace
              AND mc.connection_type = :connection_type
              AND mc.is_active = true
        SQL;

        if (null !== $connectionRef) {
            $sql .= ' AND mc.id = :connection_id';
        }

        $sql .= ' LIMIT 1';

        $row = $this->connection->fetchAssociative($sql, [
            'company_id' => $companyId,
            'marketplace' => $marketplace->value,
            'connection_type' => $connectionType->value,
        ] + (null === $connectionRef ? [] : ['connection_id' => $connectionRef]));

        if (false === $row) {
            return null;
        }

        // Encrypted-пара приоритетнее legacy plaintext (expand/contract, см. ConnectionApiKeyCodec).
        $apiKey = null !== $row['api_key_encrypted'] && null !== $row['api_key_key_version']
            ? $this->connectionApiKeyCodec->decrypt((string) $row['api_key_encrypted'], (string) $row['api_key_key_version'])
            : (string) $row['api_key'];

        return [
            'api_key' => $apiKey,
            'client_id' => null !== $row['client_id'] ? (string) $row['client_id'] : null,
        ];
    }
}
