<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add verified MoySklad account binding, encrypted tokens and optimistic version without changing legacy tokens.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE moysklad_connections ADD account_id UUID DEFAULT NULL, ADD check_status VARCHAR(32) DEFAULT 'unverified' NOT NULL, ADD last_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD last_successful_check_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD version INT DEFAULT 1 NOT NULL, ADD access_token_encrypted TEXT DEFAULT NULL, ADD refresh_token_encrypted TEXT DEFAULT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_connections_account ON moysklad_connections (account_id)');
        $this->addSql("COMMENT ON COLUMN moysklad_connections.last_checked_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN moysklad_connections.last_successful_check_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Encrypted credentials and verified account bindings must be preserved.');
    }
}
