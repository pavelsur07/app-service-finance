<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class McpOAuthStore
{
    public function __construct(private Connection $db)
    {
    }

    public function saveRequest(string $id, string $payload): void
    {
        $this->db->executeStatement('DELETE FROM mcp_oauth_requests WHERE expires_at <= CURRENT_TIMESTAMP');
        $this->db->executeStatement("INSERT INTO mcp_oauth_requests (id_hash, payload, expires_at) VALUES (?, ?, CURRENT_TIMESTAMP + INTERVAL '10 minutes')", [hash('sha256', $id), $payload]);
    }

    public function readRequest(string $id): ?string
    {
        $value = $this->db->fetchOne('SELECT payload FROM mcp_oauth_requests WHERE id_hash = ? AND expires_at > CURRENT_TIMESTAMP', [hash('sha256', $id)]);

        return \is_string($value) ? $value : null;
    }

    public function consumeRequest(string $id): ?string
    {
        $value = $this->db->fetchOne('DELETE FROM mcp_oauth_requests WHERE id_hash = ? AND expires_at > CURRENT_TIMESTAMP RETURNING payload', [hash('sha256', $id)]);

        return \is_string($value) ? $value : null;
    }

    public function saveConnection(OAuthConnection $connection): void
    {
        $this->db->executeStatement(
            'INSERT INTO mcp_oauth_connections (id, company_id, user_id, client_id, resource, scopes, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)',
            [$connection->id, $connection->companyId, $connection->userId, $connection->clientId, $connection->resource, implode(' ', $connection->scopes), $connection->expiresAt],
            [6 => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /** @return list<OAuthConnection> */
    public function connectionsForActor(string $actorId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, company_id, user_id, client_id, resource, scopes, expires_at FROM mcp_oauth_connections WHERE user_id = ? AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP ORDER BY created_at DESC, id DESC',
            [$actorId],
        );

        return array_map($this->hydrateConnection(...), $rows);
    }

    public function revokeForActor(string $connectionId, string $actorId): void
    {
        $this->db->executeStatement('UPDATE mcp_oauth_connections SET revoked_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$connectionId, $actorId]);
    }

    public function putCredential(string $kind, string $id, string $connectionId, \DateTimeImmutable $expiresAt): void
    {
        $this->db->insert('mcp_oauth_credentials', [
            'kind' => $kind,
            'id_hash' => hash('sha256', $id),
            'connection_id' => $connectionId,
            'expires_at' => $expiresAt,
        ], ['expires_at' => Types::DATETIMETZ_IMMUTABLE]);
    }

    public function bindWireToken(string $id, #[\SensitiveParameter] string $wireToken): void
    {
        $this->db->update('mcp_oauth_credentials', ['wire_hash' => hash('sha256', $wireToken)], ['id_hash' => hash('sha256', $id)]);
    }

    public function consumeCredential(string $kind, string $id): void
    {
        $this->db->executeStatement('UPDATE mcp_oauth_credentials SET consumed_at = CURRENT_TIMESTAMP WHERE kind = ? AND id_hash = ? AND consumed_at IS NULL', [$kind, hash('sha256', $id)]);
    }

    public function activeConnectionForCredential(string $kind, string $id, bool $lock = false): ?OAuthConnection
    {
        $hash = hash('sha256', $id);
        if ($lock) {
            $this->lockConnection($hash, $kind);
        }
        $row = $this->db->fetchAssociative(
            'SELECT g.id, g.company_id, g.user_id, g.client_id, g.resource, g.scopes, g.expires_at FROM mcp_oauth_credentials c
             JOIN mcp_oauth_connections g ON g.id = c.connection_id
             WHERE c.kind = ? AND c.id_hash = ? AND c.expires_at > CURRENT_TIMESTAMP AND c.consumed_at IS NULL
               AND g.revoked_at IS NULL AND g.expires_at > CURRENT_TIMESTAMP',
            [$kind, $hash],
        );

        return false === $row ? null : $this->hydrateConnection($row);
    }

    /**
     * Serialize a refresh with every other issuance or revocation for the same connection.
     * Read the credential again after obtaining the lock, including when it already expired.
     */
    public function revokeReplayedRefresh(string $clientId, #[\SensitiveParameter] string $wireToken): bool
    {
        $hash = hash('sha256', $wireToken);
        $this->lockConnection($hash, 'refresh', true);

        return 0 !== $this->db->executeStatement(
            "UPDATE mcp_oauth_connections g SET revoked_at = CURRENT_TIMESTAMP
             FROM mcp_oauth_credentials c
             WHERE c.kind = 'refresh' AND c.wire_hash = ? AND c.consumed_at IS NOT NULL
               AND g.id = c.connection_id AND g.client_id = ? AND g.revoked_at IS NULL",
            [$hash, $clientId],
        );
    }

    public function revokeByWireToken(string $clientId, string $resource, #[\SensitiveParameter] string $wireToken): void
    {
        $this->db->executeStatement(
            "UPDATE mcp_oauth_connections g SET revoked_at = CURRENT_TIMESTAMP
             FROM mcp_oauth_credentials c
             WHERE c.wire_hash = ? AND c.kind IN ('access', 'refresh') AND g.id = c.connection_id
               AND g.client_id = ? AND g.resource = ? AND g.revoked_at IS NULL",
            [hash('sha256', $wireToken), $clientId, $resource],
        );
    }

    private function lockConnection(string $hash, string $kind, bool $wire = false): void
    {
        if (!$this->db->isTransactionActive()) {
            throw new \LogicException('OAuth credential locks require a transaction.');
        }
        $column = $wire ? 'c.wire_hash' : 'c.id_hash';
        $this->db->fetchOne(
            "SELECT g.id FROM mcp_oauth_connections g JOIN mcp_oauth_credentials c ON c.connection_id = g.id WHERE c.kind = ? AND $column = ? FOR UPDATE OF g",
            [$kind, $hash],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateConnection(array $row): OAuthConnection
    {
        return new OAuthConnection(
            (string) $row['id'],
            (string) $row['company_id'],
            (string) $row['user_id'],
            (string) $row['client_id'],
            (string) $row['resource'],
            explode(' ', (string) $row['scopes']),
            new \DateTimeImmutable((string) $row['expires_at']),
        );
    }
}
