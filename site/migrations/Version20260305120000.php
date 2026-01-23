<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Wildberries commissioner v0.2 domain tables and aggregation status fields';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schema->hasTable('wildberries_commissioner_cost_types')) {
            $this->addSql('CREATE TABLE wildberries_commissioner_cost_types (id UUID NOT NULL, company_id UUID NOT NULL, code VARCHAR(64) NOT NULL, title VARCHAR(255) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql("COMMENT ON COLUMN wildberries_commissioner_cost_types.created_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_cost_type_company ON wildberries_commissioner_cost_types (company_id)');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_wb_commissioner_cost_type_company_code ON wildberries_commissioner_cost_types (company_id, code)');
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_cost_type_company') THEN ALTER TABLE wildberries_commissioner_cost_types ADD CONSTRAINT fk_wb_commissioner_cost_type_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
        }

        if (!$schema->hasTable('wildberries_commissioner_dimension_values')) {
            $this->addSql('CREATE TABLE wildberries_commissioner_dimension_values (id UUID NOT NULL, company_id UUID NOT NULL, report_id UUID NOT NULL, dimension_key VARCHAR(64) NOT NULL, value TEXT NOT NULL, normalized_value TEXT NOT NULL, occurrences INT NOT NULL DEFAULT 0, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql("COMMENT ON COLUMN wildberries_commissioner_dimension_values.created_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_dimension_value_company ON wildberries_commissioner_dimension_values (company_id)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_dimension_value_report ON wildberries_commissioner_dimension_values (report_id)');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_wb_commissioner_dimension_value_company_report_key_value ON wildberries_commissioner_dimension_values (company_id, report_id, dimension_key, normalized_value)');
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_dimension_value_company') THEN ALTER TABLE wildberries_commissioner_dimension_values ADD CONSTRAINT fk_wb_commissioner_dimension_value_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_dimension_value_report') THEN ALTER TABLE wildberries_commissioner_dimension_values ADD CONSTRAINT fk_wb_commissioner_dimension_value_report FOREIGN KEY (report_id) REFERENCES wildberries_commissioner_xlsx_reports (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
        }

        if (!$schema->hasTable('wildberries_commissioner_cost_mappings')) {
            $this->addSql('CREATE TABLE wildberries_commissioner_cost_mappings (id UUID NOT NULL, company_id UUID NOT NULL, dimension_value_id UUID NOT NULL, cost_type_id UUID NOT NULL, pl_category_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql("COMMENT ON COLUMN wildberries_commissioner_cost_mappings.created_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql("COMMENT ON COLUMN wildberries_commissioner_cost_mappings.updated_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_cost_mapping_company ON wildberries_commissioner_cost_mappings (company_id)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_cost_mapping_dimension ON wildberries_commissioner_cost_mappings (dimension_value_id)');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_wb_commissioner_cost_mapping_company_dimension ON wildberries_commissioner_cost_mappings (company_id, dimension_value_id)');
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_cost_mapping_company') THEN ALTER TABLE wildberries_commissioner_cost_mappings ADD CONSTRAINT fk_wb_commissioner_cost_mapping_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_cost_mapping_dimension') THEN ALTER TABLE wildberries_commissioner_cost_mappings ADD CONSTRAINT fk_wb_commissioner_cost_mapping_dimension FOREIGN KEY (dimension_value_id) REFERENCES wildberries_commissioner_dimension_values (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_cost_mapping_cost_type') THEN ALTER TABLE wildberries_commissioner_cost_mappings ADD CONSTRAINT fk_wb_commissioner_cost_mapping_cost_type FOREIGN KEY (cost_type_id) REFERENCES wildberries_commissioner_cost_types (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_cost_mapping_pl_category') THEN ALTER TABLE wildberries_commissioner_cost_mappings ADD CONSTRAINT fk_wb_commissioner_cost_mapping_pl_category FOREIGN KEY (pl_category_id) REFERENCES pl_categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
        }

        if (!$schema->hasTable('wildberries_commissioner_aggregation_results')) {
            $this->addSql('CREATE TABLE wildberries_commissioner_aggregation_results (id UUID NOT NULL, company_id UUID NOT NULL, report_id UUID NOT NULL, cost_type_id UUID DEFAULT NULL, dimension_value_id UUID DEFAULT NULL, amount NUMERIC(15, 2) NOT NULL, status VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql("COMMENT ON COLUMN wildberries_commissioner_aggregation_results.created_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_agg_company_report ON wildberries_commissioner_aggregation_results (company_id, report_id)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_agg_company_report_status ON wildberries_commissioner_aggregation_results (company_id, report_id, status)');
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_agg_company') THEN ALTER TABLE wildberries_commissioner_aggregation_results ADD CONSTRAINT fk_wb_commissioner_agg_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_agg_report') THEN ALTER TABLE wildberries_commissioner_aggregation_results ADD CONSTRAINT fk_wb_commissioner_agg_report FOREIGN KEY (report_id) REFERENCES wildberries_commissioner_xlsx_reports (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_agg_cost_type') THEN ALTER TABLE wildberries_commissioner_aggregation_results ADD CONSTRAINT fk_wb_commissioner_agg_cost_type FOREIGN KEY (cost_type_id) REFERENCES wildberries_commissioner_cost_types (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_agg_dimension_value') THEN ALTER TABLE wildberries_commissioner_aggregation_results ADD CONSTRAINT fk_wb_commissioner_agg_dimension_value FOREIGN KEY (dimension_value_id) REFERENCES wildberries_commissioner_dimension_values (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
        }

        if (!$schema->hasTable('wildberries_commissioner_report_rows_raw')) {
            $this->addSql('CREATE TABLE wildberries_commissioner_report_rows_raw (id UUID NOT NULL, company_id UUID NOT NULL, report_id UUID NOT NULL, row_index INT NOT NULL, data_json JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql("COMMENT ON COLUMN wildberries_commissioner_report_rows_raw.created_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_report_row_company ON wildberries_commissioner_report_rows_raw (company_id)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_report_row_report ON wildberries_commissioner_report_rows_raw (report_id)');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_wb_commissioner_report_row_report_index ON wildberries_commissioner_report_rows_raw (report_id, row_index)');
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_report_row_company') THEN ALTER TABLE wildberries_commissioner_report_rows_raw ADD CONSTRAINT fk_wb_commissioner_report_row_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_report_row_report') THEN ALTER TABLE wildberries_commissioner_report_rows_raw ADD CONSTRAINT fk_wb_commissioner_report_row_report FOREIGN KEY (report_id) REFERENCES wildberries_commissioner_xlsx_reports (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
        }

        if ($schema->hasTable('wildberries_commissioner_xlsx_reports')) {
            $table = $schemaManager->introspectTable('wildberries_commissioner_xlsx_reports');

            if (!$table->hasColumn('aggregation_status')) {
                $this->connection->executeStatement("ALTER TABLE wildberries_commissioner_xlsx_reports ADD COLUMN IF NOT EXISTS aggregation_status VARCHAR(32) DEFAULT 'not_calculated' NOT NULL");
            }

            if (!$table->hasColumn('aggregation_errors_json')) {
                $this->connection->executeStatement('ALTER TABLE wildberries_commissioner_xlsx_reports ADD COLUMN IF NOT EXISTS aggregation_errors_json JSON DEFAULT NULL');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schema->hasTable('wildberries_commissioner_xlsx_reports')) {
            $table = $schemaManager->introspectTable('wildberries_commissioner_xlsx_reports');

            if ($table->hasColumn('aggregation_errors_json')) {
                $this->addSql('ALTER TABLE wildberries_commissioner_xlsx_reports DROP COLUMN aggregation_errors_json');
            }

            if ($table->hasColumn('aggregation_status')) {
                $this->addSql('ALTER TABLE wildberries_commissioner_xlsx_reports DROP COLUMN aggregation_status');
            }
        }

        if ($schema->hasTable('wildberries_commissioner_report_rows_raw')) {
            $this->addSql('DROP TABLE wildberries_commissioner_report_rows_raw');
        }

        if ($schema->hasTable('wildberries_commissioner_aggregation_results')) {
            $this->addSql('DROP TABLE wildberries_commissioner_aggregation_results');
        }

        if ($schema->hasTable('wildberries_commissioner_cost_mappings')) {
            $this->addSql('DROP TABLE wildberries_commissioner_cost_mappings');
        }

        if ($schema->hasTable('wildberries_commissioner_dimension_values')) {
            $this->addSql('DROP TABLE wildberries_commissioner_dimension_values');
        }

        if ($schema->hasTable('wildberries_commissioner_cost_types')) {
            $this->addSql('DROP TABLE wildberries_commissioner_cost_types');
        }
    }
}
