<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

final readonly class MarketplaceCredentialsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{api_key: string, client_id: ?string}|null
     */
    public function getCredentials(
        string $companyId,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType = MarketplaceConnectionType::SELLER,
    ): ?array {
        $sql = <<<'SQL'
            SELECT mc.api_key, mc.client_id
            FROM marketplace_connections mc
            WHERE mc.company_id = :company_id
              AND mc.marketplace = :marketplace
              AND mc.connection_type = :connection_type
              AND mc.is_active = true
            LIMIT 1
        SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'company_id' => $companyId,
            'marketplace' => $marketplace->value,
            'connection_type' => $connectionType->value,
        ]);

        if (false === $row) {
            return null;
        }

        return [
            'api_key' => (string) $row['api_key'],
            'client_id' => null !== $row['client_id'] ? (string) $row['client_id'] : null,
        ];
    }
}
