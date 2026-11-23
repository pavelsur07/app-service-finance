<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;

final readonly class McpOAuthSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $requests = $schema->createTable('mcp_oauth_requests');
        $requests->addColumn('id_hash', 'string', ['length' => 64]);
        $requests->addColumn('payload', 'text');
        $requests->addColumn('expires_at', 'datetimetz_immutable');
        $requests->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id_hash')->create());
        $requests->addIndex(['expires_at'], 'idx_mcp_oauth_requests_expiry');

        $connections = $schema->createTable('mcp_oauth_connections');
        $connections->addColumn('id', 'guid');
        $connections->addColumn('company_id', 'guid');
        $connections->addColumn('user_id', 'guid');
        $connections->addColumn('client_id', 'string', ['length' => 64]);
        $connections->addColumn('resource', 'text');
        $connections->addColumn('scopes', 'text');
        $connections->addColumn('created_at', 'datetimetz_immutable');
        $connections->addColumn('expires_at', 'datetimetz_immutable');
        $connections->addColumn('revoked_at', 'datetimetz_immutable', ['notnull' => false]);
        $connections->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $connections->addIndex(['company_id'], 'idx_mcp_oauth_connections_company');
        $connections->addIndex(['user_id', 'expires_at'], 'idx_mcp_oauth_connections_user');
        $connections->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_mcp_oauth_connections_company');
        $connections->addForeignKeyConstraint('user', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_mcp_oauth_connections_user');

        $credentials = $schema->createTable('mcp_oauth_credentials');
        $credentials->addColumn('id_hash', 'string', ['length' => 64]);
        $credentials->addColumn('kind', 'string', ['length' => 8]);
        $credentials->addColumn('connection_id', 'guid');
        $credentials->addColumn('wire_hash', 'string', ['length' => 64, 'notnull' => false]);
        $credentials->addColumn('expires_at', 'datetimetz_immutable');
        $credentials->addColumn('consumed_at', 'datetimetz_immutable', ['notnull' => false]);
        $credentials->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id_hash')->create());
        $credentials->addIndex(['connection_id'], 'idx_mcp_oauth_credentials_connection');
        $credentials->addUniqueIndex(['wire_hash'], 'uniq_mcp_oauth_credentials_wire');
        $credentials->addForeignKeyConstraint('mcp_oauth_connections', ['connection_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_mcp_oauth_credentials_connection');
    }
}
