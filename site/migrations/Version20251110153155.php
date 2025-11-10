<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251110153155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bot_links (id UUID NOT NULL, company_id UUID NOT NULL, bot_id UUID NOT NULL, token VARCHAR(255) NOT NULL, scope VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_6F081784979B1AD6 ON bot_links (company_id)');
        $this->addSql('CREATE INDEX IDX_6F08178492C1C487 ON bot_links (bot_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bot_links_token ON bot_links (token)');
        $this->addSql('COMMENT ON COLUMN bot_links.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN bot_links.used_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN bot_links.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE client_bindings (id UUID NOT NULL, company_id UUID NOT NULL, bot_id UUID NOT NULL, telegram_user_id UUID NOT NULL, money_account_id UUID DEFAULT NULL, default_currency VARCHAR(3) DEFAULT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_7EBF7DBE979B1AD6 ON client_bindings (company_id)');
        $this->addSql('CREATE INDEX IDX_7EBF7DBE92C1C487 ON client_bindings (bot_id)');
        $this->addSql('CREATE INDEX IDX_7EBF7DBEFC28B263 ON client_bindings (telegram_user_id)');
        $this->addSql('CREATE INDEX IDX_7EBF7DBEB4D8145A ON client_bindings (money_account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_client_binding_company_bot_user ON client_bindings (company_id, bot_id, telegram_user_id)');
        $this->addSql('COMMENT ON COLUMN client_bindings.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN client_bindings.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE import_jobs (id UUID NOT NULL, company_id UUID NOT NULL, uploaded_by_id UUID DEFAULT NULL, source VARCHAR(32) NOT NULL, filename VARCHAR(255) NOT NULL, file_hash VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_message TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_45BF8345979B1AD6 ON import_jobs (company_id)');
        $this->addSql('CREATE INDEX IDX_45BF8345A2B28FE8 ON import_jobs (uploaded_by_id)');
        $this->addSql('CREATE INDEX idx_import_jobs_status ON import_jobs (status)');
        $this->addSql('CREATE INDEX idx_import_jobs_filehash ON import_jobs (file_hash)');
        $this->addSql('COMMENT ON COLUMN import_jobs.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN import_jobs.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN import_jobs.finished_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE report_subscriptions (id UUID NOT NULL, company_id UUID NOT NULL, telegram_user_id UUID NOT NULL, periodicity VARCHAR(16) NOT NULL, send_at_local_time VARCHAR(5) NOT NULL, timezone VARCHAR(64) DEFAULT NULL, metrics_mask INT NOT NULL, is_enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8DAACB71979B1AD6 ON report_subscriptions (company_id)');
        $this->addSql('CREATE INDEX IDX_8DAACB71FC28B263 ON report_subscriptions (telegram_user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_report_subscrib_company_user ON report_subscriptions (company_id, telegram_user_id)');
        $this->addSql('COMMENT ON COLUMN report_subscriptions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN report_subscriptions.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE telegram_bots (id UUID NOT NULL, company_id UUID NOT NULL, token VARCHAR(255) NOT NULL, username VARCHAR(128) DEFAULT NULL, webhook_url VARCHAR(255) DEFAULT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DACD6ED979B1AD6 ON telegram_bots (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_telegram_bot_token ON telegram_bots (token)');
        $this->addSql('COMMENT ON COLUMN telegram_bots.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE telegram_users (id UUID NOT NULL, tg_user_id VARCHAR(32) NOT NULL, username VARCHAR(64) DEFAULT NULL, first_name VARCHAR(128) DEFAULT NULL, last_name VARCHAR(128) DEFAULT NULL, phone VARCHAR(32) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_telegram_users_tg_user_id ON telegram_users (tg_user_id)');
        $this->addSql('COMMENT ON COLUMN telegram_users.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN telegram_users.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE bot_links ADD CONSTRAINT FK_6F081784979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE bot_links ADD CONSTRAINT FK_6F08178492C1C487 FOREIGN KEY (bot_id) REFERENCES telegram_bots (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE client_bindings ADD CONSTRAINT FK_7EBF7DBE979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE client_bindings ADD CONSTRAINT FK_7EBF7DBE92C1C487 FOREIGN KEY (bot_id) REFERENCES telegram_bots (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE client_bindings ADD CONSTRAINT FK_7EBF7DBEFC28B263 FOREIGN KEY (telegram_user_id) REFERENCES telegram_users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE client_bindings ADD CONSTRAINT FK_7EBF7DBEB4D8145A FOREIGN KEY (money_account_id) REFERENCES "money_account" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE import_jobs ADD CONSTRAINT FK_45BF8345979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE import_jobs ADD CONSTRAINT FK_45BF8345A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES telegram_users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE report_subscriptions ADD CONSTRAINT FK_8DAACB71979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE report_subscriptions ADD CONSTRAINT FK_8DAACB71FC28B263 FOREIGN KEY (telegram_user_id) REFERENCES telegram_users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE telegram_bots ADD CONSTRAINT FK_DACD6ED979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist(['wildberries_rnp_daily'])) {
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER orders_count_spp DROP DEFAULT');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER orders_sum_spp_minor DROP DEFAULT');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER sales_count_spp DROP DEFAULT');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER sales_sum_spp_minor DROP DEFAULT');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER ad_cost_sum_minor DROP DEFAULT');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER buyout_rate DROP DEFAULT');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER cogs_sum_spp_minor DROP DEFAULT');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE bot_links DROP CONSTRAINT FK_6F081784979B1AD6');
        $this->addSql('ALTER TABLE bot_links DROP CONSTRAINT FK_6F08178492C1C487');
        $this->addSql('ALTER TABLE client_bindings DROP CONSTRAINT FK_7EBF7DBE979B1AD6');
        $this->addSql('ALTER TABLE client_bindings DROP CONSTRAINT FK_7EBF7DBE92C1C487');
        $this->addSql('ALTER TABLE client_bindings DROP CONSTRAINT FK_7EBF7DBEFC28B263');
        $this->addSql('ALTER TABLE client_bindings DROP CONSTRAINT FK_7EBF7DBEB4D8145A');
        $this->addSql('ALTER TABLE import_jobs DROP CONSTRAINT FK_45BF8345979B1AD6');
        $this->addSql('ALTER TABLE import_jobs DROP CONSTRAINT FK_45BF8345A2B28FE8');
        $this->addSql('ALTER TABLE report_subscriptions DROP CONSTRAINT FK_8DAACB71979B1AD6');
        $this->addSql('ALTER TABLE report_subscriptions DROP CONSTRAINT FK_8DAACB71FC28B263');
        $this->addSql('ALTER TABLE telegram_bots DROP CONSTRAINT FK_DACD6ED979B1AD6');
        $this->addSql('DROP TABLE bot_links');
        $this->addSql('DROP TABLE client_bindings');
        $this->addSql('DROP TABLE import_jobs');
        $this->addSql('DROP TABLE report_subscriptions');
        $this->addSql('DROP TABLE telegram_bots');
        $this->addSql('DROP TABLE telegram_users');
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist(['wildberries_rnp_daily'])) {
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER orders_count_spp SET DEFAULT 0');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER orders_sum_spp_minor SET DEFAULT 0');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER sales_count_spp SET DEFAULT 0');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER sales_sum_spp_minor SET DEFAULT 0');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER ad_cost_sum_minor SET DEFAULT 0');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER buyout_rate SET DEFAULT \'0\'');
            $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER cogs_sum_spp_minor SET DEFAULT 0');
        }
    }
}
