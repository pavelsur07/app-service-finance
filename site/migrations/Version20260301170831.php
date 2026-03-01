<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260301170831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE ai_agent (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              type VARCHAR(255) NOT NULL,
              is_enabled BOOLEAN NOT NULL,
              settings CLOB NOT NULL --(DC2Type:json)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_7A3CA7D8979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_7A3CA7D8979B1AD6 ON ai_agent (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_agent_company_type ON ai_agent (company_id, type)');
        $this->addSql(<<<'SQL'
            CREATE TABLE ai_run (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              agent_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              started_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              finished_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              status VARCHAR(255) NOT NULL,
              input_summary CLOB DEFAULT NULL,
              output CLOB DEFAULT NULL,
              error_message CLOB DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_DA110A46979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_DA110A463414710B FOREIGN KEY (agent_id) REFERENCES ai_agent (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_DA110A46979B1AD6 ON ai_run (company_id)');
        $this->addSql('CREATE INDEX IDX_DA110A463414710B ON ai_run (agent_id)');
        $this->addSql('CREATE INDEX idx_ai_run_company_started ON ai_run (company_id, started_at)');
        $this->addSql(<<<'SQL'
            CREATE TABLE ai_suggestion (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              agent_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              run_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              title VARCHAR(255) NOT NULL,
              description CLOB NOT NULL,
              severity VARCHAR(255) NOT NULL,
              is_read BOOLEAN NOT NULL,
              is_applied BOOLEAN NOT NULL,
              related_entity_type VARCHAR(191) DEFAULT NULL,
              related_entity_id VARCHAR(64) DEFAULT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_BCA6A14F979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_BCA6A14F3414710B FOREIGN KEY (agent_id) REFERENCES ai_agent (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_BCA6A14F84E3FEC4 FOREIGN KEY (run_id) REFERENCES ai_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_BCA6A14F979B1AD6 ON ai_suggestion (company_id)');
        $this->addSql('CREATE INDEX IDX_BCA6A14F3414710B ON ai_suggestion (agent_id)');
        $this->addSql('CREATE INDEX IDX_BCA6A14F84E3FEC4 ON ai_suggestion (run_id)');
        $this->addSql('CREATE INDEX idx_ai_suggestion_company_created ON ai_suggestion (company_id, created_at)');
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              entity_class VARCHAR(255) NOT NULL,
              entity_id VARCHAR(255) NOT NULL,
              "action" VARCHAR(255) NOT NULL,
              diff CLOB DEFAULT NULL --(DC2Type:json)
              ,
              actor_user_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_audit_log_company_created_at ON audit_log (company_id, created_at)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_audit_log_entity_created_at ON audit_log (
              entity_class, entity_id, created_at
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_categories (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              parent_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              name VARCHAR(255) NOT NULL,
              code VARCHAR(64) DEFAULT NULL,
              type VARCHAR(255) NOT NULL,
              level INTEGER DEFAULT 1 NOT NULL,
              sort_order INTEGER DEFAULT 0 NOT NULL,
              is_visible BOOLEAN DEFAULT 1 NOT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_6250404A979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_6250404A727ACA70 FOREIGN KEY (parent_id) REFERENCES balance_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_6250404A979B1AD6 ON balance_categories (company_id)');
        $this->addSql('CREATE INDEX IDX_6250404A727ACA70 ON balance_categories (parent_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_category_links (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              category_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              source_type VARCHAR(255) NOT NULL,
              source_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              sign INTEGER DEFAULT 1 NOT NULL,
              position INTEGER DEFAULT 0 NOT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_D79DC3BA979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_D79DC3BA12469DE2 FOREIGN KEY (category_id) REFERENCES balance_categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_D79DC3BA979B1AD6 ON balance_category_links (company_id)');
        $this->addSql('CREATE INDEX IDX_D79DC3BA12469DE2 ON balance_category_links (category_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_feature (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              code VARCHAR(255) NOT NULL,
              type VARCHAR(255) NOT NULL,
              name VARCHAR(255) NOT NULL,
              description VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_feature_code ON billing_feature (code)');
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_integration (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              code VARCHAR(255) NOT NULL,
              name VARCHAR(255) NOT NULL,
              billing_type VARCHAR(255) NOT NULL,
              price_amount INTEGER DEFAULT NULL,
              price_currency VARCHAR(3) DEFAULT NULL,
              is_active BOOLEAN NOT NULL,
              PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_billing_integration_is_active ON billing_integration (is_active)');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_integration_code ON billing_integration (code)');
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_plan (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              code VARCHAR(255) NOT NULL,
              name VARCHAR(255) NOT NULL,
              price_amount INTEGER NOT NULL,
              price_currency VARCHAR(3) NOT NULL,
              billing_period VARCHAR(255) NOT NULL,
              is_active BOOLEAN NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_billing_plan_is_active ON billing_plan (is_active)');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_plan_code ON billing_plan (code)');
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_plan_feature (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              plan_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              feature_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              value VARCHAR(255) NOT NULL,
              soft_limit INTEGER DEFAULT NULL,
              hard_limit INTEGER DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_143F769BE899029B FOREIGN KEY (plan_id) REFERENCES billing_plan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_143F769B60E4B879 FOREIGN KEY (feature_id) REFERENCES billing_feature (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_143F769BE899029B ON billing_plan_feature (plan_id)');
        $this->addSql('CREATE INDEX IDX_143F769B60E4B879 ON billing_plan_feature (feature_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_billing_plan_feature_plan_feature ON billing_plan_feature (plan_id, feature_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_subscription (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              plan_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              status VARCHAR(255) NOT NULL,
              trial_ends_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              current_period_start DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              current_period_end DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              cancel_at_period_end BOOLEAN NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_16912F26979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_16912F26E899029B FOREIGN KEY (plan_id) REFERENCES billing_plan (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_16912F26979B1AD6 ON billing_subscription (company_id)');
        $this->addSql('CREATE INDEX IDX_16912F26E899029B ON billing_subscription (plan_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_billing_subscription_company_status_period_end ON billing_subscription (
              company_id, status, current_period_end
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_subscription_integration (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              subscription_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              integration_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              status VARCHAR(255) NOT NULL,
              started_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              ended_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_B08F02409A1887DC FOREIGN KEY (subscription_id) REFERENCES billing_subscription (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_B08F02409E82DDEA FOREIGN KEY (integration_id) REFERENCES billing_integration (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_B08F02409A1887DC ON billing_subscription_integration (subscription_id)');
        $this->addSql('CREATE INDEX IDX_B08F02409E82DDEA ON billing_subscription_integration (integration_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_billing_subscription_integration_subscription_integration ON billing_subscription_integration (subscription_id, integration_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_usage_counter (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              period_key VARCHAR(255) NOT NULL,
              metric VARCHAR(255) NOT NULL,
              used INTEGER NOT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_52C4B3BF979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_52C4B3BF979B1AD6 ON billing_usage_counter (company_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_billing_usage_counter_company_period_metric ON billing_usage_counter (company_id, period_key, metric)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "bot_links" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              bot_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              token VARCHAR(255) NOT NULL,
              scope VARCHAR(64) NOT NULL,
              expires_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              used_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_6F081784979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_6F08178492C1C487 FOREIGN KEY (bot_id) REFERENCES "telegram_bots" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_6F081784979B1AD6 ON "bot_links" (company_id)');
        $this->addSql('CREATE INDEX IDX_6F08178492C1C487 ON "bot_links" (bot_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bot_links_token ON "bot_links" (token)');
        $this->addSql(<<<'SQL'
            CREATE TABLE cash_bank_connection (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              bank_code VARCHAR(32) NOT NULL,
              api_key VARCHAR(255) NOT NULL,
              base_url VARCHAR(255) NOT NULL,
              is_active BOOLEAN NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_B43A74ED979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_B43A74ED979B1AD6 ON cash_bank_connection (company_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE cash_bank_import_cursor (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              bank_code VARCHAR(32) NOT NULL,
              account_number VARCHAR(64) NOT NULL,
              last_imported_date DATE DEFAULT NULL --(DC2Type:date_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_FEBA0CA0979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_FEBA0CA0979B1AD6 ON cash_bank_import_cursor (company_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_bank_import_cursor_company_bank_account ON cash_bank_import_cursor (
              company_id, bank_code, account_number
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "cash_file_import_jobs" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              money_account_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              import_log_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              source VARCHAR(32) NOT NULL,
              filename VARCHAR(255) NOT NULL,
              file_hash VARCHAR(64) NOT NULL,
              status VARCHAR(16) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              started_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              finished_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              error_message CLOB DEFAULT NULL,
              mapping CLOB NOT NULL --(DC2Type:json)
              ,
              options CLOB NOT NULL --(DC2Type:json)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_3F785CF7979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_3F785CF7B4D8145A FOREIGN KEY (money_account_id) REFERENCES "money_account" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_3F785CF79656E79E FOREIGN KEY (import_log_id) REFERENCES import_log (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_3F785CF7B4D8145A ON "cash_file_import_jobs" (money_account_id)');
        $this->addSql('CREATE INDEX IDX_3F785CF79656E79E ON "cash_file_import_jobs" (import_log_id)');
        $this->addSql('CREATE INDEX idx_cash_file_import_jobs_status ON "cash_file_import_jobs" (status)');
        $this->addSql('CREATE INDEX idx_cash_file_import_jobs_filehash ON "cash_file_import_jobs" (file_hash)');
        $this->addSql('CREATE INDEX idx_cash_file_import_jobs_company ON "cash_file_import_jobs" (company_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE cash_file_import_profile (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              name VARCHAR(255) NOT NULL,
              type VARCHAR(64) NOT NULL,
              mapping CLOB NOT NULL --(DC2Type:json)
              ,
              options CLOB NOT NULL --(DC2Type:json)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_573DA724979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_cash_file_import_profile_company ON cash_file_import_profile (company_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_cash_file_import_profile_company_type ON cash_file_import_profile (company_id, type)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE cash_transaction (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              money_account_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              counterparty_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              cashflow_category_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              project_direction_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              direction VARCHAR(255) NOT NULL,
              amount NUMERIC(18, 2) NOT NULL,
              vat_rate_percent SMALLINT DEFAULT NULL,
              vat_amount NUMERIC(18, 2) DEFAULT NULL,
              currency VARCHAR(3) NOT NULL,
              occurred_at DATE NOT NULL --(DC2Type:date_immutable)
              ,
              booked_at DATE NOT NULL --(DC2Type:date_immutable)
              ,
              description VARCHAR(1024) DEFAULT NULL,
              doc_type VARCHAR(64) DEFAULT NULL,
              doc_number VARCHAR(64) DEFAULT NULL,
              external_id VARCHAR(128) DEFAULT NULL,
              import_source VARCHAR(32) DEFAULT NULL,
              dedupe_hash VARCHAR(64) DEFAULT NULL,
              is_transfer BOOLEAN NOT NULL,
              raw_data CLOB NOT NULL --(DC2Type:json)
              ,
              allocated_amount NUMERIC(18, 2) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL,
              deleted_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              deleted_by VARCHAR(64) DEFAULT NULL,
              delete_reason VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_925602C6979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_925602C6B4D8145A FOREIGN KEY (money_account_id) REFERENCES "money_account" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_925602C6DB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_925602C6C2F6CD90 FOREIGN KEY (cashflow_category_id) REFERENCES "cashflow_categories" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_925602C685D43DF4 FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_925602C6979B1AD6 ON cash_transaction (company_id)');
        $this->addSql('CREATE INDEX IDX_925602C6B4D8145A ON cash_transaction (money_account_id)');
        $this->addSql('CREATE INDEX IDX_925602C6DB1FAD05 ON cash_transaction (counterparty_id)');
        $this->addSql('CREATE INDEX IDX_925602C6C2F6CD90 ON cash_transaction (cashflow_category_id)');
        $this->addSql('CREATE INDEX IDX_925602C685D43DF4 ON cash_transaction (project_direction_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_company_account_occurred ON cash_transaction (
              company_id, money_account_id, occurred_at
            )
        SQL);
        $this->addSql('CREATE INDEX idx_company_occurred ON cash_transaction (company_id, occurred_at)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_cash_transaction_company_is_transfer ON cash_transaction (company_id, is_transfer)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_cashflow_import ON cash_transaction (
              company_id, import_source, external_id
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE cash_transaction_auto_rule (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              counterparty_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              cashflow_category_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              project_direction_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              name VARCHAR(255) NOT NULL,
              "action" VARCHAR(255) NOT NULL,
              operation_type VARCHAR(255) NOT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_882E16E9979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_882E16E9DB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_882E16E9C2F6CD90 FOREIGN KEY (cashflow_category_id) REFERENCES "cashflow_categories" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_882E16E985D43DF4 FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_882E16E985D43DF4 ON cash_transaction_auto_rule (project_direction_id)');
        $this->addSql('CREATE INDEX idx_ctar_company ON cash_transaction_auto_rule (company_id)');
        $this->addSql('CREATE INDEX idx_ctar_category ON cash_transaction_auto_rule (cashflow_category_id)');
        $this->addSql('CREATE INDEX idx_ctar_counterparty ON cash_transaction_auto_rule (counterparty_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE cash_transaction_auto_rule_condition (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              auto_rule_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              counterparty_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              field VARCHAR(255) NOT NULL,
              operator VARCHAR(255) NOT NULL,
              value VARCHAR(255) DEFAULT NULL,
              value_to VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_B362E0EF5E0B4E6B FOREIGN KEY (auto_rule_id) REFERENCES cash_transaction_auto_rule (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_B362E0EFDB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_B362E0EFDB1FAD05 ON cash_transaction_auto_rule_condition (counterparty_id)');
        $this->addSql('CREATE INDEX idx_ctarc_rule ON cash_transaction_auto_rule_condition (auto_rule_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "cashflow_categories" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              parent_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              pl_category_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              name VARCHAR(255) NOT NULL,
              description CLOB DEFAULT NULL,
              status VARCHAR(255) NOT NULL,
              sort INTEGER NOT NULL,
              operation_type VARCHAR(255) DEFAULT NULL,
              allow_pl_document BOOLEAN DEFAULT 0 NOT NULL,
              system_code VARCHAR(32) DEFAULT NULL,
              flow_kind VARCHAR(255) NOT NULL,
              is_system BOOLEAN DEFAULT 0 NOT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_EAB5C38D727ACA70 FOREIGN KEY (parent_id) REFERENCES "cashflow_categories" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_EAB5C38D979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_EAB5C38D98B34054 FOREIGN KEY (pl_category_id) REFERENCES pl_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_EAB5C38D727ACA70 ON "cashflow_categories" (parent_id)');
        $this->addSql('CREATE INDEX IDX_EAB5C38D979B1AD6 ON "cashflow_categories" (company_id)');
        $this->addSql('CREATE INDEX IDX_EAB5C38D98B34054 ON "cashflow_categories" (pl_category_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "client_bindings" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              bot_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              telegram_user_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              money_account_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              default_currency VARCHAR(3) DEFAULT NULL,
              status VARCHAR(16) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_7EBF7DBE979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_7EBF7DBE92C1C487 FOREIGN KEY (bot_id) REFERENCES "telegram_bots" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_7EBF7DBEFC28B263 FOREIGN KEY (telegram_user_id) REFERENCES "telegram_users" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_7EBF7DBEB4D8145A FOREIGN KEY (money_account_id) REFERENCES "money_account" (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_7EBF7DBE979B1AD6 ON "client_bindings" (company_id)');
        $this->addSql('CREATE INDEX IDX_7EBF7DBE92C1C487 ON "client_bindings" (bot_id)');
        $this->addSql('CREATE INDEX IDX_7EBF7DBEFC28B263 ON "client_bindings" (telegram_user_id)');
        $this->addSql('CREATE INDEX IDX_7EBF7DBEB4D8145A ON "client_bindings" (money_account_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_client_binding_company_bot_user ON "client_bindings" (
              company_id, bot_id, telegram_user_id
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "companies" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              user_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              name VARCHAR(255) NOT NULL,
              inn VARCHAR(12) DEFAULT NULL,
              finance_lock_before DATE DEFAULT NULL --(DC2Type:date_immutable)
              ,
              tax_system VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_8244AA3AA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_8244AA3AA76ED395 ON "companies" (user_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE company_invites (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              created_by_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              accepted_by_user_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              email VARCHAR(180) NOT NULL,
              role VARCHAR(32) NOT NULL,
              token_hash VARCHAR(255) NOT NULL,
              expires_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              accepted_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              revoked_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_232C70BB979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_232C70BBB03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_232C70BB110B274A FOREIGN KEY (accepted_by_user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_232C70BBB3BC57DA ON company_invites (token_hash)');
        $this->addSql('CREATE INDEX IDX_232C70BB979B1AD6 ON company_invites (company_id)');
        $this->addSql('CREATE INDEX IDX_232C70BBB03A8386 ON company_invites (created_by_id)');
        $this->addSql('CREATE INDEX IDX_232C70BB110B274A ON company_invites (accepted_by_user_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE company_members (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              user_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              role VARCHAR(32) NOT NULL,
              status VARCHAR(32) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_65F2C828979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_65F2C828A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_65F2C828979B1AD6 ON company_members (company_id)');
        $this->addSql('CREATE INDEX IDX_65F2C828A76ED395 ON company_members (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_members_company_user ON company_members (company_id, user_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "counterparty" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              name VARCHAR(255) NOT NULL,
              inn VARCHAR(12) DEFAULT NULL,
              type VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL,
              is_archived BOOLEAN NOT NULL,
              average_delay_days INTEGER DEFAULT NULL,
              reliability_score INTEGER DEFAULT 100 NOT NULL,
              last_scored_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_9B3DE79C979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_counterparty_company ON "counterparty" (company_id)');
        $this->addSql('CREATE INDEX idx_counterparty_company_inn ON "counterparty" (company_id, inn)');
        $this->addSql(<<<'SQL'
            CREATE TABLE deal_adjustments (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              deal_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              type VARCHAR(32) NOT NULL,
              recognized_at DATE NOT NULL --(DC2Type:date_immutable)
              ,
              amount NUMERIC(18, 2) NOT NULL,
              comment VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_3FD28B74F60E2305 FOREIGN KEY (deal_id) REFERENCES deals (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_deal_adjustment_deal ON deal_adjustments (deal_id)');
        $this->addSql('CREATE INDEX idx_deal_adjustment_recognized_at ON deal_adjustments (recognized_at)');
        $this->addSql(<<<'SQL'
            CREATE TABLE deal_charge_types (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              code VARCHAR(64) NOT NULL,
              name VARCHAR(255) NOT NULL,
              is_active BOOLEAN DEFAULT 1 NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_CDCCF0D1979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_CDCCF0D1979B1AD6 ON deal_charge_types (company_id)');
        $this->addSql('CREATE INDEX idx_deal_charge_type_company_active ON deal_charge_types (company_id, is_active)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deal_charge_type_company_code ON deal_charge_types (company_id, code)');
        $this->addSql(<<<'SQL'
            CREATE TABLE deal_charges (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              deal_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              charge_type_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              recognized_at DATE NOT NULL --(DC2Type:date_immutable)
              ,
              amount NUMERIC(18, 2) NOT NULL,
              comment VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_DEF5D892F60E2305 FOREIGN KEY (deal_id) REFERENCES deals (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_DEF5D89291A77836 FOREIGN KEY (charge_type_id) REFERENCES deal_charge_types (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_deal_charge_deal ON deal_charges (deal_id)');
        $this->addSql('CREATE INDEX idx_deal_charge_charge_type ON deal_charges (charge_type_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE deal_items (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              deal_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              name VARCHAR(255) NOT NULL,
              kind VARCHAR(255) NOT NULL,
              unit VARCHAR(32) DEFAULT NULL,
              qty NUMERIC(18, 2) NOT NULL,
              price NUMERIC(18, 2) NOT NULL,
              amount NUMERIC(18, 2) NOT NULL,
              line_index INTEGER NOT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_A7C12A51F60E2305 FOREIGN KEY (deal_id) REFERENCES deals (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_deal_item_deal ON deal_items (deal_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deal_item_deal_line_index ON deal_items (deal_id, line_index)');
        $this->addSql(<<<'SQL'
            CREATE TABLE deals (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              counterparty_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              number VARCHAR(64) NOT NULL,
              title VARCHAR(255) DEFAULT NULL,
              type VARCHAR(32) NOT NULL,
              channel VARCHAR(32) NOT NULL,
              status VARCHAR(32) DEFAULT 'draft' NOT NULL,
              recognized_at DATE NOT NULL --(DC2Type:date_immutable)
              ,
              occurred_at DATE DEFAULT NULL --(DC2Type:date_immutable)
              ,
              currency VARCHAR(3) DEFAULT NULL,
              total_amount NUMERIC(18, 2) DEFAULT '0' NOT NULL,
              total_direct_cost NUMERIC(18, 2) DEFAULT '0' NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_EF39849B979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_EF39849BDB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_EF39849B979B1AD6 ON deals (company_id)');
        $this->addSql('CREATE INDEX IDX_EF39849BDB1FAD05 ON deals (counterparty_id)');
        $this->addSql('CREATE INDEX idx_deal_company_recognized_at ON deals (company_id, recognized_at)');
        $this->addSql('CREATE INDEX idx_deal_company_status ON deals (company_id, status)');
        $this->addSql('CREATE INDEX idx_deal_company_counterparty ON deals (company_id, counterparty_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deal_company_number ON deals (company_id, number)');
        $this->addSql(<<<'SQL'
            CREATE TABLE document_operations (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              document_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              category_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              counterparty_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              project_direction_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              amount NUMERIC(15, 2) NOT NULL,
              comment VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_89D7F81EC33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_89D7F81E12469DE2 FOREIGN KEY (category_id) REFERENCES pl_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_89D7F81EDB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_89D7F81E85D43DF4 FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_89D7F81EC33F7837 ON document_operations (document_id)');
        $this->addSql('CREATE INDEX IDX_89D7F81E12469DE2 ON document_operations (category_id)');
        $this->addSql('CREATE INDEX IDX_89D7F81EDB1FAD05 ON document_operations (counterparty_id)');
        $this->addSql('CREATE INDEX IDX_89D7F81E85D43DF4 ON document_operations (project_direction_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE documents (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              cash_transaction_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              counterparty_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              project_direction_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              date DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              number VARCHAR(255) DEFAULT NULL,
              description CLOB DEFAULT NULL,
              type VARCHAR(255) DEFAULT 'OTHER' NOT NULL,
              status VARCHAR(255) DEFAULT 'ACTIVE' NOT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_A2B07288979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_A2B07288435DB913 FOREIGN KEY (cash_transaction_id) REFERENCES cash_transaction (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_A2B07288DB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_A2B0728885D43DF4 FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_A2B07288979B1AD6 ON documents (company_id)');
        $this->addSql('CREATE INDEX IDX_A2B07288435DB913 ON documents (cash_transaction_id)');
        $this->addSql('CREATE INDEX IDX_A2B07288DB1FAD05 ON documents (counterparty_id)');
        $this->addSql('CREATE INDEX IDX_A2B0728885D43DF4 ON documents (project_direction_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_loan (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              pl_category_interest_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              pl_category_fee_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              name VARCHAR(255) NOT NULL,
              lender_name VARCHAR(255) DEFAULT NULL,
              principal_amount NUMERIC(18, 2) NOT NULL,
              remaining_principal NUMERIC(18, 2) NOT NULL,
              interest_rate NUMERIC(8, 4) DEFAULT NULL,
              start_date DATE NOT NULL --(DC2Type:date_immutable)
              ,
              end_date DATE DEFAULT NULL --(DC2Type:date_immutable)
              ,
              payment_day_of_month SMALLINT DEFAULT NULL,
              include_principal_in_pnl BOOLEAN DEFAULT 0 NOT NULL,
              status VARCHAR(32) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_6AC7F603979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_6AC7F603C20545F8 FOREIGN KEY (pl_category_interest_id) REFERENCES pl_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_6AC7F6037D43AC52 FOREIGN KEY (pl_category_fee_id) REFERENCES pl_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_6AC7F603979B1AD6 ON finance_loan (company_id)');
        $this->addSql('CREATE INDEX IDX_6AC7F603C20545F8 ON finance_loan (pl_category_interest_id)');
        $this->addSql('CREATE INDEX IDX_6AC7F6037D43AC52 ON finance_loan (pl_category_fee_id)');
        $this->addSql('CREATE INDEX idx_finance_loan_company_status ON finance_loan (company_id, status)');
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_loan_payment_schedule (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              loan_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              due_date DATE NOT NULL --(DC2Type:date_immutable)
              ,
              total_payment_amount NUMERIC(18, 2) NOT NULL,
              principal_part NUMERIC(18, 2) NOT NULL,
              interest_part NUMERIC(18, 2) NOT NULL,
              fee_part NUMERIC(18, 2) NOT NULL,
              is_paid BOOLEAN NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_71389BC4CE73868F FOREIGN KEY (loan_id) REFERENCES finance_loan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_finance_loan_payment_schedule_loan ON finance_loan_payment_schedule (loan_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_finance_loan_payment_schedule_due_date ON finance_loan_payment_schedule (due_date)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "import_jobs" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              uploaded_by_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              source VARCHAR(32) NOT NULL,
              filename VARCHAR(255) NOT NULL,
              file_hash VARCHAR(64) NOT NULL,
              status VARCHAR(16) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              started_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              finished_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              error_message CLOB DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_45BF8345979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_45BF8345A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES "telegram_users" (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_45BF8345979B1AD6 ON "import_jobs" (company_id)');
        $this->addSql('CREATE INDEX IDX_45BF8345A2B28FE8 ON "import_jobs" (uploaded_by_id)');
        $this->addSql('CREATE INDEX idx_import_jobs_status ON "import_jobs" (status)');
        $this->addSql('CREATE INDEX idx_import_jobs_filehash ON "import_jobs" (file_hash)');
        $this->addSql(<<<'SQL'
            CREATE TABLE import_log (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              source VARCHAR(64) NOT NULL,
              started_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              finished_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              created_count INTEGER NOT NULL,
              skipped_duplicates INTEGER NOT NULL,
              errors_count INTEGER NOT NULL,
              preview BOOLEAN NOT NULL,
              user_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              file_name VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_1B52C845979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_1B52C845979B1AD6 ON import_log (company_id)');
        $this->addSql('CREATE INDEX idx_import_log_company_started ON import_log (company_id, started_at)');
        $this->addSql('CREATE INDEX idx_import_log_company_finished ON import_log (company_id, finished_at)');
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_connections (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              marketplace VARCHAR(255) NOT NULL,
              api_key CLOB NOT NULL,
              client_id VARCHAR(100) DEFAULT NULL,
              is_active BOOLEAN NOT NULL,
              last_sync_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              last_successful_sync_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              last_sync_error CLOB DEFAULT NULL,
              settings CLOB DEFAULT NULL --(DC2Type:json)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_85B5ED50979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_connection_company ON marketplace_connections (company_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_company_marketplace ON marketplace_connections (company_id, marketplace)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_cost_categories (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              pl_category_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              marketplace VARCHAR(255) NOT NULL,
              name VARCHAR(100) NOT NULL,
              code VARCHAR(50) NOT NULL,
              description CLOB DEFAULT NULL,
              is_system BOOLEAN DEFAULT 0 NOT NULL,
              deleted_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              is_active BOOLEAN NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_E0E21EA3979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_E0E21EA398B34054 FOREIGN KEY (pl_category_id) REFERENCES pl_categories (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_E0E21EA398B34054 ON marketplace_cost_categories (pl_category_id)');
        $this->addSql('CREATE INDEX idx_cost_category_company ON marketplace_cost_categories (company_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_company_marketplace_code ON marketplace_cost_categories (company_id, marketplace, code)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_costs (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              category_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              listing_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              product_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              sale_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              marketplace VARCHAR(255) NOT NULL,
              amount NUMERIC(10, 2) NOT NULL,
              cost_date DATE NOT NULL --(DC2Type:date_immutable)
              ,
              description CLOB DEFAULT NULL,
              external_id VARCHAR(100) DEFAULT NULL,
              raw_document_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              raw_data CLOB DEFAULT NULL --(DC2Type:json)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_BDEE8EF979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_BDEE8EF12469DE2 FOREIGN KEY (category_id) REFERENCES marketplace_cost_categories (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_BDEE8EFD4619D1A FOREIGN KEY (listing_id) REFERENCES marketplace_listings (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_BDEE8EF4584665A FOREIGN KEY (product_id) REFERENCES "products" (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_BDEE8EF4A7E4868 FOREIGN KEY (sale_id) REFERENCES marketplace_sales (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_BDEE8EF979B1AD6 ON marketplace_costs (company_id)');
        $this->addSql('CREATE INDEX idx_company_cost_date ON marketplace_costs (company_id, cost_date)');
        $this->addSql('CREATE INDEX idx_cost_category ON marketplace_costs (category_id)');
        $this->addSql('CREATE INDEX idx_cost_listing ON marketplace_costs (listing_id)');
        $this->addSql('CREATE INDEX idx_cost_product ON marketplace_costs (product_id)');
        $this->addSql('CREATE INDEX idx_cost_sale ON marketplace_costs (sale_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_listings (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              product_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              marketplace VARCHAR(255) NOT NULL,
              marketplace_sku VARCHAR(100) NOT NULL,
              supplier_sku VARCHAR(255) DEFAULT NULL,
              size VARCHAR(50) NOT NULL,
              price NUMERIC(10, 2) NOT NULL,
              discount_price NUMERIC(10, 2) DEFAULT NULL,
              is_active BOOLEAN NOT NULL,
              marketplace_data CLOB DEFAULT NULL --(DC2Type:json)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              name VARCHAR(500) DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_8E9E6312979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_8E9E63124584665A FOREIGN KEY (product_id) REFERENCES "products" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_8E9E6312979B1AD6 ON marketplace_listings (company_id)');
        $this->addSql('CREATE INDEX IDX_8E9E63124584665A ON marketplace_listings (product_id)');
        $this->addSql('CREATE INDEX idx_company_marketplace ON marketplace_listings (company_id, marketplace)');
        $this->addSql('CREATE INDEX idx_marketplace_sku ON marketplace_listings (marketplace, marketplace_sku)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_company_marketplace_sku_size ON marketplace_listings (
              company_id, marketplace, marketplace_sku,
              size
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_processing_batch (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              raw_document_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              total_records INTEGER NOT NULL,
              sales_records INTEGER NOT NULL,
              return_records INTEGER NOT NULL,
              cost_records INTEGER NOT NULL,
              storno_records INTEGER NOT NULL,
              processed_records INTEGER NOT NULL,
              failed_records INTEGER NOT NULL,
              skipped_records INTEGER NOT NULL,
              status VARCHAR(20) NOT NULL,
              error_message CLOB DEFAULT NULL,
              reconciliation_data CLOB DEFAULT NULL --(DC2Type:json)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              started_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              completed_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_A9BAED9A979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_A9BAED9A490B70FF FOREIGN KEY (raw_document_id) REFERENCES marketplace_raw_documents (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_A9BAED9A979B1AD6 ON marketplace_processing_batch (company_id)');
        $this->addSql('CREATE INDEX idx_batch_company_status ON marketplace_processing_batch (company_id, status)');
        $this->addSql('CREATE INDEX idx_batch_raw_doc ON marketplace_processing_batch (raw_document_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_raw_documents (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              marketplace VARCHAR(255) NOT NULL,
              document_type VARCHAR(50) NOT NULL,
              period_from DATE NOT NULL --(DC2Type:date_immutable)
              ,
              period_to DATE NOT NULL --(DC2Type:date_immutable)
              ,
              raw_data CLOB NOT NULL --(DC2Type:json)
              ,
              api_endpoint VARCHAR(255) NOT NULL,
              records_count INTEGER NOT NULL,
              records_created INTEGER NOT NULL,
              records_skipped INTEGER NOT NULL,
              synced_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              sync_notes CLOB DEFAULT NULL,
              unprocessed_costs_count INTEGER DEFAULT 0 NOT NULL,
              unprocessed_cost_types CLOB DEFAULT NULL --(DC2Type:json)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_5A2AF248979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_5A2AF248979B1AD6 ON marketplace_raw_documents (company_id)');
        $this->addSql('CREATE INDEX idx_company_synced ON marketplace_raw_documents (company_id, synced_at)');
        $this->addSql('CREATE INDEX idx_marketplace_type ON marketplace_raw_documents (marketplace, document_type)');
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_reconciliation_log (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              processing_batch_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              check_type VARCHAR(50) NOT NULL,
              passed BOOLEAN NOT NULL,
              details CLOB NOT NULL --(DC2Type:json)
              ,
              checked_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_38B4D6D9428AC9A FOREIGN KEY (processing_batch_id) REFERENCES marketplace_processing_batch (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_38B4D6D9428AC9A ON marketplace_reconciliation_log (processing_batch_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_recon_batch_type ON marketplace_reconciliation_log (processing_batch_id, check_type)
        SQL);
        $this->addSql('CREATE INDEX idx_recon_passed ON marketplace_reconciliation_log (passed)');
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_returns (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              listing_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              sale_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              product_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              marketplace VARCHAR(255) NOT NULL,
              external_return_id VARCHAR(100) DEFAULT NULL,
              return_date DATE NOT NULL --(DC2Type:date_immutable)
              ,
              quantity INTEGER NOT NULL,
              refund_amount NUMERIC(10, 2) NOT NULL,
              return_reason VARCHAR(100) DEFAULT NULL,
              raw_document_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              raw_data CLOB DEFAULT NULL --(DC2Type:json)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_6FF09FB9979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_6FF09FB9D4619D1A FOREIGN KEY (listing_id) REFERENCES marketplace_listings (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_6FF09FB94A7E4868 FOREIGN KEY (sale_id) REFERENCES marketplace_sales (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_6FF09FB94584665A FOREIGN KEY (product_id) REFERENCES "products" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_6FF09FB9979B1AD6 ON marketplace_returns (company_id)');
        $this->addSql('CREATE INDEX IDX_6FF09FB9D4619D1A ON marketplace_returns (listing_id)');
        $this->addSql('CREATE INDEX IDX_6FF09FB94584665A ON marketplace_returns (product_id)');
        $this->addSql('CREATE INDEX idx_company_return_date ON marketplace_returns (company_id, return_date)');
        $this->addSql('CREATE INDEX idx_return_sale ON marketplace_returns (sale_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_sales (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              listing_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              product_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              document_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              marketplace VARCHAR(255) NOT NULL,
              external_order_id VARCHAR(100) NOT NULL,
              sale_date DATE NOT NULL --(DC2Type:date_immutable)
              ,
              quantity INTEGER NOT NULL,
              price_per_unit NUMERIC(10, 2) NOT NULL,
              cost_price NUMERIC(10, 2) DEFAULT NULL,
              total_revenue NUMERIC(10, 2) NOT NULL,
              raw_document_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              raw_data CLOB DEFAULT NULL --(DC2Type:json)
              ,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_CF42CF03979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_CF42CF03D4619D1A FOREIGN KEY (listing_id) REFERENCES marketplace_listings (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_CF42CF034584665A FOREIGN KEY (product_id) REFERENCES "products" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_CF42CF03C33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_CF42CF03979B1AD6 ON marketplace_sales (company_id)');
        $this->addSql('CREATE INDEX IDX_CF42CF03D4619D1A ON marketplace_sales (listing_id)');
        $this->addSql('CREATE INDEX IDX_CF42CF034584665A ON marketplace_sales (product_id)');
        $this->addSql('CREATE INDEX IDX_CF42CF03C33F7837 ON marketplace_sales (document_id)');
        $this->addSql('CREATE INDEX idx_company_sale_date ON marketplace_sales (company_id, sale_date)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_marketplace_srid ON marketplace_sales (marketplace, external_order_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_staging (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              processing_batch_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              listing_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              marketplace VARCHAR(30) NOT NULL,
              source_record_id VARCHAR(255) NOT NULL,
              record_type VARCHAR(20) NOT NULL,
              raw_data CLOB NOT NULL --(DC2Type:json)
              ,
              amount NUMERIC(15, 2) NOT NULL,
              record_date DATE NOT NULL --(DC2Type:date_immutable)
              ,
              marketplace_sku VARCHAR(100) NOT NULL,
              parsed_data CLOB DEFAULT NULL --(DC2Type:json)
              ,
              linked_to_product BOOLEAN NOT NULL,
              processing_status VARCHAR(20) NOT NULL,
              validation_errors CLOB DEFAULT NULL --(DC2Type:json)
              ,
              final_entity_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              final_entity_type VARCHAR(50) DEFAULT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              processed_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_59B45A39979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_59B45A399428AC9A FOREIGN KEY (processing_batch_id) REFERENCES marketplace_processing_batch (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_59B45A39D4619D1A FOREIGN KEY (listing_id) REFERENCES marketplace_listings (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_59B45A39979B1AD6 ON marketplace_staging (company_id)');
        $this->addSql('CREATE INDEX IDX_59B45A399428AC9A ON marketplace_staging (processing_batch_id)');
        $this->addSql('CREATE INDEX IDX_59B45A39D4619D1A ON marketplace_staging (listing_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_staging_batch_status ON marketplace_staging (
              processing_batch_id, processing_status
            )
        SQL);
        $this->addSql('CREATE INDEX idx_staging_company_status ON marketplace_staging (company_id, processing_status)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_staging_mp_type_status ON marketplace_staging (
              marketplace, record_type, processing_status
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_marketplace_source_record ON marketplace_staging (marketplace, source_record_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "money_account" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              type VARCHAR(255) NOT NULL,
              name VARCHAR(150) NOT NULL,
              currency VARCHAR(3) NOT NULL,
              is_active BOOLEAN NOT NULL,
              is_default BOOLEAN NOT NULL,
              opening_balance NUMERIC(18, 2) NOT NULL,
              opening_balance_date DATE NOT NULL --(DC2Type:date_immutable)
              ,
              current_balance NUMERIC(18, 2) NOT NULL,
              minimum_safe_balance NUMERIC(14, 2) NOT NULL,
              sort_order INTEGER NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL,
              bank_name VARCHAR(150) DEFAULT NULL,
              account_number VARCHAR(64) DEFAULT NULL,
              iban VARCHAR(34) DEFAULT NULL,
              bic VARCHAR(20) DEFAULT NULL,
              corr_account VARCHAR(64) DEFAULT NULL,
              location VARCHAR(150) DEFAULT NULL,
              responsible_person VARCHAR(150) DEFAULT NULL,
              provider VARCHAR(100) DEFAULT NULL,
              wallet_id VARCHAR(100) DEFAULT NULL,
              meta CLOB DEFAULT NULL --(DC2Type:json)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_195D60EF979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_195D60EF979B1AD6 ON "money_account" (company_id)');
        $this->addSql('CREATE INDEX idx_company_type ON "money_account" (company_id, type)');
        $this->addSql('CREATE INDEX idx_company_currency_active ON "money_account" (company_id, currency, is_active)');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_name ON "money_account" (company_id, name)');
        $this->addSql(<<<'SQL'
            CREATE TABLE money_account_daily_balance (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              money_account_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              date DATE NOT NULL --(DC2Type:date_immutable)
              ,
              opening_balance NUMERIC(18, 2) NOT NULL,
              inflow NUMERIC(18, 2) NOT NULL,
              outflow NUMERIC(18, 2) NOT NULL,
              closing_balance NUMERIC(18, 2) NOT NULL,
              currency VARCHAR(3) NOT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_FCE321EB979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_FCE321EBB4D8145A FOREIGN KEY (money_account_id) REFERENCES "money_account" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_FCE321EB979B1AD6 ON money_account_daily_balance (company_id)');
        $this->addSql('CREATE INDEX IDX_FCE321EBB4D8145A ON money_account_daily_balance (money_account_id)');
        $this->addSql('CREATE INDEX idx_company_date ON money_account_daily_balance (company_id, date)');
        $this->addSql('CREATE INDEX idx_account_date ON money_account_daily_balance (money_account_id, date)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_company_account_date ON money_account_daily_balance (
              company_id, money_account_id, date
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "money_fund" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              name VARCHAR(150) NOT NULL,
              description CLOB DEFAULT NULL,
              currency VARCHAR(3) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_331E9D17979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_money_fund_company ON "money_fund" (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_money_fund_company_name ON "money_fund" (company_id, name)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "money_fund_movement" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              fund_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              occurred_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              amount_minor BIGINT NOT NULL,
              note CLOB DEFAULT NULL,
              user_id VARCHAR(64) DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_1336CC9F979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_1336CC9F25A38F89 FOREIGN KEY (fund_id) REFERENCES "money_fund" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_1336CC9F979B1AD6 ON "money_fund_movement" (company_id)');
        $this->addSql('CREATE INDEX IDX_1336CC9F25A38F89 ON "money_fund_movement" (fund_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_money_fund_movement_company_fund ON "money_fund_movement" (company_id, fund_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_money_fund_movement_company_occurred_at ON "money_fund_movement" (company_id, occurred_at)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_plan (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              money_account_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              cashflow_category_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              counterparty_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              recurrence_rule_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              expected_at DATE DEFAULT NULL --(DC2Type:date_immutable)
              ,
              document_date DATE NOT NULL --(DC2Type:date_immutable)
              ,
              probability SMALLINT DEFAULT 100 NOT NULL,
              source VARCHAR(255) DEFAULT 'MANUAL' NOT NULL,
              is_frozen BOOLEAN DEFAULT 0 NOT NULL,
              external_id VARCHAR(255) DEFAULT NULL,
              amount NUMERIC(14, 2) NOT NULL,
              status VARCHAR(255) NOT NULL,
              type VARCHAR(255) NOT NULL,
              comment CLOB DEFAULT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_FCD9CC09979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_FCD9CC09B4D8145A FOREIGN KEY (money_account_id) REFERENCES "money_account" (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_FCD9CC09C2F6CD90 FOREIGN KEY (cashflow_category_id) REFERENCES "cashflow_categories" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_FCD9CC09DB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_FCD9CC092344888A FOREIGN KEY (recurrence_rule_id) REFERENCES payment_recurrence_rule (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_FCD9CC09979B1AD6 ON payment_plan (company_id)');
        $this->addSql('CREATE INDEX IDX_FCD9CC09B4D8145A ON payment_plan (money_account_id)');
        $this->addSql('CREATE INDEX IDX_FCD9CC09C2F6CD90 ON payment_plan (cashflow_category_id)');
        $this->addSql('CREATE INDEX IDX_FCD9CC09DB1FAD05 ON payment_plan (counterparty_id)');
        $this->addSql('CREATE INDEX IDX_FCD9CC092344888A ON payment_plan (recurrence_rule_id)');
        $this->addSql('CREATE INDEX idx_payment_plan_company_expected_at ON payment_plan (company_id, expected_at)');
        $this->addSql('CREATE INDEX idx_payment_plan_company_status ON payment_plan (company_id, status)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_payment_plan_company_category ON payment_plan (
              company_id, cashflow_category_id
            )
        SQL);
        $this->addSql('CREATE INDEX idx_payment_plan_company_account ON payment_plan (company_id, money_account_id)');
        $this->addSql('CREATE INDEX idx_payment_plan_external_sync ON payment_plan (company_id, source, external_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_plan_match (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              plan_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              transaction_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              matched_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_1C0B012C979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_1C0B012CE899029B FOREIGN KEY (plan_id) REFERENCES payment_plan (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_1C0B012C2FC0CB0F FOREIGN KEY (transaction_id) REFERENCES cash_transaction (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_1C0B012C979B1AD6 ON payment_plan_match (company_id)');
        $this->addSql('CREATE INDEX IDX_1C0B012CE899029B ON payment_plan_match (plan_id)');
        $this->addSql('CREATE INDEX idx_payment_plan_match_company_plan ON payment_plan_match (company_id, plan_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_payment_plan_match_company_transaction ON payment_plan_match (company_id, transaction_id)
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_plan_match_transaction ON payment_plan_match (transaction_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_recurrence_rule (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              frequency VARCHAR(16) NOT NULL,
              interval INTEGER DEFAULT 1 NOT NULL,
              by_day VARCHAR(32) DEFAULT NULL,
              day_of_month INTEGER DEFAULT NULL,
              until DATE DEFAULT NULL --(DC2Type:date_immutable)
              ,
              active BOOLEAN NOT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_C1F00EE7979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_C1F00EE7979B1AD6 ON payment_recurrence_rule (company_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_payment_recurrence_company_active ON payment_recurrence_rule (company_id, active)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE pl_categories (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              parent_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              name VARCHAR(255) NOT NULL,
              level INTEGER NOT NULL,
              sort_order INTEGER NOT NULL,
              code VARCHAR(64) DEFAULT NULL,
              type VARCHAR(255) DEFAULT 'LEAF_INPUT' NOT NULL,
              format VARCHAR(255) DEFAULT 'MONEY' NOT NULL,
              flow VARCHAR(255) DEFAULT 'NONE' NOT NULL,
              expense_type VARCHAR(255) DEFAULT 'other' NOT NULL,
              weight_in_parent NUMERIC(10, 4) DEFAULT '1.0000' NOT NULL,
              is_visible BOOLEAN DEFAULT 1 NOT NULL,
              formula CLOB DEFAULT NULL,
              calc_order INTEGER DEFAULT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_AAE71C73979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_AAE71C73727ACA70 FOREIGN KEY (parent_id) REFERENCES pl_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_AAE71C73979B1AD6 ON pl_categories (company_id)');
        $this->addSql('CREATE INDEX IDX_AAE71C73727ACA70 ON pl_categories (parent_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE pl_daily_totals (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              pl_category_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              project_direction_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              date DATE NOT NULL --(DC2Type:date_immutable)
              ,
              amount_income NUMERIC(18, 2) NOT NULL,
              amount_expense NUMERIC(18, 2) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_34A948D1979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_34A948D198B34054 FOREIGN KEY (pl_category_id) REFERENCES pl_categories (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_34A948D185D43DF4 FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_34A948D1979B1AD6 ON pl_daily_totals (company_id)');
        $this->addSql('CREATE INDEX IDX_34A948D198B34054 ON pl_daily_totals (pl_category_id)');
        $this->addSql('CREATE INDEX IDX_34A948D185D43DF4 ON pl_daily_totals (project_direction_id)');
        $this->addSql('CREATE INDEX idx_pl_daily_company_date ON pl_daily_totals (company_id, date)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_pl_daily_company_cat_date ON pl_daily_totals (
              company_id, pl_category_id, date,
              project_direction_id
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE pl_monthly_snapshots (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              pl_category_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              period VARCHAR(7) NOT NULL,
              amount_income NUMERIC(18, 2) NOT NULL,
              amount_expense NUMERIC(18, 2) NOT NULL,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_BC3D760D979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_BC3D760D98B34054 FOREIGN KEY (pl_category_id) REFERENCES pl_categories (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_BC3D760D979B1AD6 ON pl_monthly_snapshots (company_id)');
        $this->addSql('CREATE INDEX IDX_BC3D760D98B34054 ON pl_monthly_snapshots (pl_category_id)');
        $this->addSql('CREATE INDEX idx_pl_monthly_company_period ON pl_monthly_snapshots (company_id, period)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_pl_monthly_company_cat_period ON pl_monthly_snapshots (
              company_id, pl_category_id, period
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product_purchase_prices (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              product_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              effective_from DATE NOT NULL --(DC2Type:date_immutable)
              ,
              effective_to DATE DEFAULT NULL --(DC2Type:date_immutable)
              ,
              price_amount BIGINT NOT NULL,
              price_currency VARCHAR(3) DEFAULT 'RUB' NOT NULL,
              note VARCHAR(255) DEFAULT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_D2F6A91979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_D2F6A914584665A FOREIGN KEY (product_id) REFERENCES "products" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_D2F6A91979B1AD6 ON product_purchase_prices (company_id)');
        $this->addSql('CREATE INDEX IDX_D2F6A914584665A ON product_purchase_prices (product_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "products" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              sku VARCHAR(100) NOT NULL,
              name VARCHAR(255) NOT NULL,
              description CLOB DEFAULT NULL,
              purchase_price NUMERIC(10, 2) NOT NULL,
              weight_kg NUMERIC(8, 3) DEFAULT NULL,
              dimensions CLOB DEFAULT NULL --(DC2Type:json)
              ,
              status VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_B3BA5A5A979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_B3BA5A5A979B1AD6 ON "products" (company_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE project_directions (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              parent_id CHAR(36) DEFAULT NULL --(DC2Type:guid)
              ,
              name VARCHAR(255) NOT NULL,
              sort INTEGER NOT NULL,
              PRIMARY KEY(id),
              CONSTRAINT FK_51FE1CDE979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_51FE1CDE727ACA70 FOREIGN KEY (parent_id) REFERENCES project_directions (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_51FE1CDE979B1AD6 ON project_directions (company_id)');
        $this->addSql('CREATE INDEX IDX_51FE1CDE727ACA70 ON project_directions (parent_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "report_api_key" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              key_prefix VARCHAR(16) NOT NULL,
              key_hash CLOB NOT NULL,
              scopes VARCHAR(255) NOT NULL,
              is_active BOOLEAN NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              last_used_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              expires_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_A98B909D979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_report_api_key_company ON "report_api_key" (company_id)');
        $this->addSql('CREATE INDEX idx_report_api_key_key_prefix ON "report_api_key" (key_prefix)');
        $this->addSql('CREATE INDEX idx_report_api_key_is_active ON "report_api_key" (is_active)');
        $this->addSql('CREATE INDEX idx_report_api_key_prefix_active ON "report_api_key" (key_prefix, is_active)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "report_subscriptions" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              company_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              telegram_user_id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              periodicity VARCHAR(16) NOT NULL,
              send_at_local_time VARCHAR(5) NOT NULL,
              timezone VARCHAR(64) DEFAULT NULL,
              metrics_mask INTEGER NOT NULL,
              is_enabled BOOLEAN NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id),
              CONSTRAINT FK_8DAACB71979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_8DAACB71FC28B263 FOREIGN KEY (telegram_user_id) REFERENCES "telegram_users" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_8DAACB71979B1AD6 ON "report_subscriptions" (company_id)');
        $this->addSql('CREATE INDEX IDX_8DAACB71FC28B263 ON "report_subscriptions" (telegram_user_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_report_subscrib_company_user ON "report_subscriptions" (company_id, telegram_user_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "telegram_bots" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              token VARCHAR(255) NOT NULL,
              username VARCHAR(128) DEFAULT NULL,
              webhook_url VARCHAR(255) DEFAULT NULL,
              is_active BOOLEAN NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_telegram_bot_token ON "telegram_bots" (token)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "telegram_users" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              tg_user_id VARCHAR(32) NOT NULL,
              username VARCHAR(64) DEFAULT NULL,
              first_name VARCHAR(128) DEFAULT NULL,
              last_name VARCHAR(128) DEFAULT NULL,
              phone VARCHAR(32) DEFAULT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_telegram_users_tg_user_id ON "telegram_users" (tg_user_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "user" (
              id CHAR(36) NOT NULL --(DC2Type:guid)
              ,
              email VARCHAR(180) NOT NULL,
              roles CLOB NOT NULL --(DC2Type:json)
              ,
              password VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON "user" (email)');
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              body CLOB NOT NULL,
              headers CLOB NOT NULL,
              queue_name VARCHAR(190) NOT NULL,
              created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              available_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
              ,
              delivered_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (
              queue_name, available_at, delivered_at,
              id
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE ai_agent');
        $this->addSql('DROP TABLE ai_run');
        $this->addSql('DROP TABLE ai_suggestion');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE balance_categories');
        $this->addSql('DROP TABLE balance_category_links');
        $this->addSql('DROP TABLE billing_feature');
        $this->addSql('DROP TABLE billing_integration');
        $this->addSql('DROP TABLE billing_plan');
        $this->addSql('DROP TABLE billing_plan_feature');
        $this->addSql('DROP TABLE billing_subscription');
        $this->addSql('DROP TABLE billing_subscription_integration');
        $this->addSql('DROP TABLE billing_usage_counter');
        $this->addSql('DROP TABLE "bot_links"');
        $this->addSql('DROP TABLE cash_bank_connection');
        $this->addSql('DROP TABLE cash_bank_import_cursor');
        $this->addSql('DROP TABLE "cash_file_import_jobs"');
        $this->addSql('DROP TABLE cash_file_import_profile');
        $this->addSql('DROP TABLE cash_transaction');
        $this->addSql('DROP TABLE cash_transaction_auto_rule');
        $this->addSql('DROP TABLE cash_transaction_auto_rule_condition');
        $this->addSql('DROP TABLE "cashflow_categories"');
        $this->addSql('DROP TABLE "client_bindings"');
        $this->addSql('DROP TABLE "companies"');
        $this->addSql('DROP TABLE company_invites');
        $this->addSql('DROP TABLE company_members');
        $this->addSql('DROP TABLE "counterparty"');
        $this->addSql('DROP TABLE deal_adjustments');
        $this->addSql('DROP TABLE deal_charge_types');
        $this->addSql('DROP TABLE deal_charges');
        $this->addSql('DROP TABLE deal_items');
        $this->addSql('DROP TABLE deals');
        $this->addSql('DROP TABLE document_operations');
        $this->addSql('DROP TABLE documents');
        $this->addSql('DROP TABLE finance_loan');
        $this->addSql('DROP TABLE finance_loan_payment_schedule');
        $this->addSql('DROP TABLE "import_jobs"');
        $this->addSql('DROP TABLE import_log');
        $this->addSql('DROP TABLE marketplace_connections');
        $this->addSql('DROP TABLE marketplace_cost_categories');
        $this->addSql('DROP TABLE marketplace_costs');
        $this->addSql('DROP TABLE marketplace_listings');
        $this->addSql('DROP TABLE marketplace_processing_batch');
        $this->addSql('DROP TABLE marketplace_raw_documents');
        $this->addSql('DROP TABLE marketplace_reconciliation_log');
        $this->addSql('DROP TABLE marketplace_returns');
        $this->addSql('DROP TABLE marketplace_sales');
        $this->addSql('DROP TABLE marketplace_staging');
        $this->addSql('DROP TABLE "money_account"');
        $this->addSql('DROP TABLE money_account_daily_balance');
        $this->addSql('DROP TABLE "money_fund"');
        $this->addSql('DROP TABLE "money_fund_movement"');
        $this->addSql('DROP TABLE payment_plan');
        $this->addSql('DROP TABLE payment_plan_match');
        $this->addSql('DROP TABLE payment_recurrence_rule');
        $this->addSql('DROP TABLE pl_categories');
        $this->addSql('DROP TABLE pl_daily_totals');
        $this->addSql('DROP TABLE pl_monthly_snapshots');
        $this->addSql('DROP TABLE product_purchase_prices');
        $this->addSql('DROP TABLE "products"');
        $this->addSql('DROP TABLE project_directions');
        $this->addSql('DROP TABLE "report_api_key"');
        $this->addSql('DROP TABLE "report_subscriptions"');
        $this->addSql('DROP TABLE "telegram_bots"');
        $this->addSql('DROP TABLE "telegram_users"');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
