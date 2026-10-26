<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BankSync: bank_sync_connection.status (+ reason, changed_at) replaces is_active';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE bank_sync_connection ADD status VARCHAR(16) DEFAULT 'active' NOT NULL");
        $this->addSql('ALTER TABLE bank_sync_connection ADD status_reason VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE bank_sync_connection ADD status_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE bank_sync_connection SET status = CASE WHEN is_active THEN 'active' ELSE 'disabled' END, status_changed_at = updated_at");
        $this->addSql('ALTER TABLE bank_sync_connection ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE bank_sync_connection ALTER status_changed_at SET NOT NULL');
        $this->addSql("ALTER TABLE bank_sync_connection ADD CONSTRAINT chk_bank_sync_connection_status CHECK (status IN ('active', 'needs_reauth', 'revoked', 'disabled'))");
        $this->addSql('ALTER TABLE bank_sync_connection DROP is_active');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_sync_connection ADD is_active BOOLEAN DEFAULT true NOT NULL');
        // needs_reauth и revoked загружать нельзя — возвращаются выключенными.
        $this->addSql("UPDATE bank_sync_connection SET is_active = (status = 'active')");
        $this->addSql('ALTER TABLE bank_sync_connection ALTER is_active DROP DEFAULT');
        $this->addSql('ALTER TABLE bank_sync_connection DROP CONSTRAINT chk_bank_sync_connection_status');
        $this->addSql('ALTER TABLE bank_sync_connection DROP status_changed_at');
        $this->addSql('ALTER TABLE bank_sync_connection DROP status_reason');
        $this->addSql('ALTER TABLE bank_sync_connection DROP status');
    }
}
