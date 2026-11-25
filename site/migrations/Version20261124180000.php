<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MoySklad stores → Balance accounts: links, posting settings, daily valuation by MoySklad cost and per account-day postings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_store_balance_settings (
                company_id UUID NOT NULL, connection_id UUID NOT NULL, actor_user_id UUID NOT NULL,
                counter_account_id UUID DEFAULT NULL, enabled BOOLEAN DEFAULT false NOT NULL,
                updated_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (company_id, connection_id)
            )
            SQL);
        $this->addSql('ALTER TABLE moysklad_store_balance_settings ADD CONSTRAINT fk_ms_store_balance_settings_connection FOREIGN KEY (company_id, connection_id) REFERENCES moysklad_connections (company_id, id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_store_balance_links (
                company_id UUID NOT NULL, connection_id UUID NOT NULL, store_external_id UUID NOT NULL, actor_user_id UUID NOT NULL,
                balance_account_id UUID DEFAULT NULL, excluded BOOLEAN DEFAULT false NOT NULL,
                updated_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (company_id, connection_id, store_external_id)
            )
            SQL);
        $this->addSql('ALTER TABLE moysklad_store_balance_links ADD CONSTRAINT fk_ms_store_balance_links_store FOREIGN KEY (company_id, connection_id, store_external_id) REFERENCES moysklad_stores (company_id, connection_id, external_id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_store_balance_runs (
                company_id UUID NOT NULL, connection_id UUID NOT NULL, snapshot_id UUID NOT NULL,
                valuation_date DATE NOT NULL, status VARCHAR(16) NOT NULL, error_reason VARCHAR(64) DEFAULT NULL,
                attempts INT DEFAULT 0 NOT NULL,
                created_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(3) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (company_id, snapshot_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_ms_store_balance_runs_date ON moysklad_store_balance_runs (company_id, connection_id, valuation_date)');
        $this->addSql('ALTER TABLE moysklad_store_balance_runs ADD CONSTRAINT fk_ms_store_balance_runs_snapshot FOREIGN KEY (company_id, connection_id, snapshot_id) REFERENCES moysklad_stock_snapshots (company_id, connection_id, id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_store_valuations (
                company_id UUID NOT NULL, connection_id UUID NOT NULL, snapshot_id UUID NOT NULL, store_external_id UUID NOT NULL,
                valuation_date DATE NOT NULL, quantity NUMERIC(30, 10) NOT NULL, amount_minor BIGINT NOT NULL,
                unpriced_positions INT DEFAULT 0 NOT NULL,
                missing_positions INT DEFAULT 0 NOT NULL,
                PRIMARY KEY (company_id, snapshot_id, store_external_id)
            )
            SQL);
        $this->addSql('ALTER TABLE moysklad_store_valuations ADD CONSTRAINT fk_ms_store_valuations_run FOREIGN KEY (company_id, snapshot_id) REFERENCES moysklad_store_balance_runs (company_id, snapshot_id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE moysklad_store_valuations ADD CONSTRAINT fk_ms_store_valuations_store FOREIGN KEY (company_id, connection_id, store_external_id) REFERENCES moysklad_stores (company_id, connection_id, external_id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_store_balance_postings (
                company_id UUID NOT NULL, connection_id UUID NOT NULL, balance_account_id UUID NOT NULL, snapshot_id UUID NOT NULL,
                valuation_date DATE NOT NULL, target_minor BIGINT NOT NULL, status VARCHAR(16) NOT NULL,
                reason VARCHAR(255) DEFAULT NULL, operation_id UUID DEFAULT NULL,
                updated_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (company_id, connection_id, valuation_date, balance_account_id)
            )
            SQL);
        $this->addSql('ALTER TABLE moysklad_store_balance_postings ADD CONSTRAINT fk_ms_store_balance_postings_run FOREIGN KEY (company_id, snapshot_id) REFERENCES moysklad_store_balance_runs (company_id, snapshot_id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moysklad_store_balance_postings');
        $this->addSql('DROP TABLE moysklad_store_valuations');
        $this->addSql('DROP TABLE moysklad_store_balance_runs');
        $this->addSql('DROP TABLE moysklad_store_balance_links');
        $this->addSql('DROP TABLE moysklad_store_balance_settings');
    }
}
