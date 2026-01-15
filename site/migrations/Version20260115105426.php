<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260115105426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cash_bank_connection (id UUID NOT NULL, company_id UUID NOT NULL, bank_code VARCHAR(32) NOT NULL, api_key VARCHAR(255) NOT NULL, base_url VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_B43A74ED979B1AD6 ON cash_bank_connection (company_id)');
        $this->addSql('COMMENT ON COLUMN cash_bank_connection.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN cash_bank_connection.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE cash_bank_import_cursor (id UUID NOT NULL, company_id UUID NOT NULL, bank_code VARCHAR(32) NOT NULL, account_number VARCHAR(64) NOT NULL, last_imported_date DATE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FEBA0CA0979B1AD6 ON cash_bank_import_cursor (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bank_import_cursor_company_bank_account ON cash_bank_import_cursor (company_id, bank_code, account_number)');
        $this->addSql('COMMENT ON COLUMN cash_bank_import_cursor.last_imported_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN cash_bank_import_cursor.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE cash_bank_connection ADD CONSTRAINT FK_B43A74ED979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cash_bank_import_cursor ADD CONSTRAINT FK_FEBA0CA0979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE balance_categories DROP CONSTRAINT fk_balance_categories_parent');
        $this->addSql('DROP INDEX idx_balance_cat_company_parent');
        $this->addSql('DROP INDEX uniq_balance_cat_company_code');
        $this->addSql('ALTER TABLE balance_categories ALTER type TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE balance_categories ADD CONSTRAINT FK_6250404A727ACA70 FOREIGN KEY (parent_id) REFERENCES balance_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER INDEX idx_balance_cat_company RENAME TO IDX_6250404A979B1AD6');
        $this->addSql('DROP INDEX idx_balance_link_company_category');
        $this->addSql('DROP INDEX uniq_balance_link');
        $this->addSql('ALTER TABLE balance_category_links ALTER source_type TYPE VARCHAR(255)');
        $this->addSql('ALTER INDEX idx_balance_link_company RENAME TO IDX_D79DC3BA979B1AD6');
        if ($schema->hasTable('bot_links') && !$schema->getTable('bot_links')->hasColumn('updated_at')) {
            $this->addSql('ALTER TABLE bot_links ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        }

        if ($schema->hasTable('bot_links') && $schema->getTable('bot_links')->hasColumn('updated_at')) {
            $this->addSql('COMMENT ON COLUMN bot_links.updated_at IS \'(DC2Type:datetime_immutable)\'');
        }
        $this->addSql('ALTER TABLE cash_transaction ALTER allocated_amount DROP DEFAULT');
        $this->addSql('ALTER INDEX idx_cashflow_categories_pl_category RENAME TO IDX_EAB5C38D98B34054');
        $this->addSql('ALTER TABLE document_operations DROP CONSTRAINT fk_doc_ops_project_direction');
        $this->addSql('ALTER TABLE document_operations ALTER project_direction_id DROP NOT NULL');
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_class WHERE relname = 'idx_doc_oper_project_direction') THEN ALTER INDEX idx_doc_oper_project_direction RENAME TO IDX_89D7F81E85D43DF4; END IF; END $$;");
        $this->addSql('ALTER TABLE documents ALTER project_direction_id DROP NOT NULL');
        $this->addSql('ALTER TABLE documents ALTER status TYPE VARCHAR(255)');
        $this->addSql('ALTER INDEX idx_documents_cash_transaction RENAME TO IDX_A2B07288435DB913');
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_class WHERE relname = 'idx_documents_project_direction') THEN ALTER INDEX idx_documents_project_direction RENAME TO IDX_A2B0728885D43DF4; END IF; END $$;");
        $this->addSql('ALTER TABLE finance_loan ALTER start_date TYPE DATE');
        $this->addSql('ALTER TABLE finance_loan ALTER end_date TYPE DATE');
        $this->addSql('COMMENT ON COLUMN finance_loan.start_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN finance_loan.end_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('ALTER TABLE finance_loan_payment_schedule ALTER due_date TYPE DATE');
        $this->addSql('COMMENT ON COLUMN finance_loan_payment_schedule.due_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('DROP INDEX idx_pl_daily_company_cat_date');
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_constraint WHERE lower(conname) = lower('fk_dacd6ed979b1ad6')) THEN ALTER TABLE telegram_bots DROP CONSTRAINT fk_dacd6ed979b1ad6; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_class WHERE relname = 'idx_dacd6ed979b1ad6') THEN DROP INDEX idx_dacd6ed979b1ad6; END IF; END $$;");
        $this->addSql('ALTER TABLE telegram_bots ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE telegram_bots DROP company_id');
        $this->addSql('COMMENT ON COLUMN telegram_bots.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER INDEX idx_wb_report_detail_mappings_company RENAME TO IDX_C7426B75979B1AD6');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER orders_count_spp DROP DEFAULT');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER orders_sum_spp_minor DROP DEFAULT');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER sales_count_spp DROP DEFAULT');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER sales_sum_spp_minor DROP DEFAULT');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER ad_cost_sum_minor DROP DEFAULT');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER buyout_rate DROP DEFAULT');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER cogs_sum_spp_minor DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE cash_bank_connection DROP CONSTRAINT FK_B43A74ED979B1AD6');
        $this->addSql('ALTER TABLE cash_bank_import_cursor DROP CONSTRAINT FK_FEBA0CA0979B1AD6');
        $this->addSql('DROP TABLE cash_bank_connection');
        $this->addSql('DROP TABLE cash_bank_import_cursor');
        $this->addSql('ALTER TABLE documents ALTER project_direction_id SET NOT NULL');
        $this->addSql('ALTER TABLE documents ALTER status TYPE VARCHAR(32)');
        $this->addSql('ALTER INDEX idx_a2b07288435db913 RENAME TO idx_documents_cash_transaction');
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_class WHERE relname = 'idx_a2b0728885d43df4') THEN ALTER INDEX idx_a2b0728885d43df4 RENAME TO idx_documents_project_direction; END IF; END $$;");
        $this->addSql('ALTER TABLE document_operations ALTER project_direction_id SET NOT NULL');
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_class WHERE relname = 'idx_89d7f81e85d43df4') THEN ALTER INDEX idx_89d7f81e85d43df4 RENAME TO idx_doc_oper_project_direction; END IF; END $$;");
        $this->addSql('CREATE INDEX idx_pl_daily_company_cat_date ON pl_daily_totals (company_id, pl_category_id, date, project_direction_id)');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER orders_count_spp SET DEFAULT 0');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER orders_sum_spp_minor SET DEFAULT 0');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER sales_count_spp SET DEFAULT 0');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER sales_sum_spp_minor SET DEFAULT 0');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER ad_cost_sum_minor SET DEFAULT 0');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER buyout_rate SET DEFAULT \'0\'');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ALTER cogs_sum_spp_minor SET DEFAULT 0');
        $this->addSql('ALTER INDEX idx_c7426b75979b1ad6 RENAME TO idx_wb_report_detail_mappings_company');
        $this->addSql('ALTER TABLE balance_category_links ALTER source_type TYPE VARCHAR(64)');
        $this->addSql('CREATE INDEX idx_balance_link_company_category ON balance_category_links (company_id, category_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_balance_link ON balance_category_links (company_id, category_id, source_type, source_id)');
        $this->addSql('ALTER INDEX idx_d79dc3ba979b1ad6 RENAME TO idx_balance_link_company');
        $this->addSql('ALTER TABLE cash_transaction ALTER allocated_amount SET DEFAULT \'0\'');
        $this->addSql('ALTER TABLE balance_categories DROP CONSTRAINT FK_6250404A727ACA70');
        $this->addSql('ALTER TABLE balance_categories ALTER type TYPE VARCHAR(32)');
        $this->addSql('ALTER TABLE balance_categories ADD CONSTRAINT fk_balance_categories_parent FOREIGN KEY (parent_id) REFERENCES balance_categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_balance_cat_company_parent ON balance_categories (company_id, parent_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_balance_cat_company_code ON balance_categories (company_id, code)');
        $this->addSql('ALTER INDEX idx_6250404a979b1ad6 RENAME TO idx_balance_cat_company');
        $this->addSql('ALTER TABLE finance_loan_payment_schedule ALTER due_date TYPE DATE');
        $this->addSql('COMMENT ON COLUMN finance_loan_payment_schedule.due_date IS NULL');
        $this->addSql('ALTER TABLE "telegram_bots" ADD company_id UUID NOT NULL');
        $this->addSql('ALTER TABLE "telegram_bots" DROP updated_at');
        $this->addSql('ALTER TABLE "telegram_bots" ADD CONSTRAINT fk_dacd6ed979b1ad6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_dacd6ed979b1ad6 ON "telegram_bots" (company_id)');
        if ($schema->hasTable('bot_links') && $schema->getTable('bot_links')->hasColumn('updated_at')) {
            $this->addSql('ALTER TABLE "bot_links" DROP updated_at');
        }
        $this->addSql('ALTER INDEX idx_eab5c38d98b34054 RENAME TO idx_cashflow_categories_pl_category');
        $this->addSql('ALTER TABLE finance_loan ALTER start_date TYPE DATE');
        $this->addSql('ALTER TABLE finance_loan ALTER end_date TYPE DATE');
        $this->addSql('COMMENT ON COLUMN finance_loan.start_date IS NULL');
        $this->addSql('COMMENT ON COLUMN finance_loan.end_date IS NULL');
    }
}
