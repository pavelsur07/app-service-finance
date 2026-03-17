<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260326000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_month_closes and marketplace_cost_pl_mappings tables';
    }

    public function up(Schema $schema): void
    {
        // --- marketplace_month_closes ---
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_month_closes (
                id              UUID         NOT NULL,
                company_id      UUID         NOT NULL,
                marketplace     VARCHAR(50)  NOT NULL,
                year            SMALLINT     NOT NULL,
                month           SMALLINT     NOT NULL,

                stage_sales_returns_status              VARCHAR(20)  NOT NULL DEFAULT 'pending',
                stage_sales_returns_closed_at           TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                stage_sales_returns_closed_by_user_id   UUID         DEFAULT NULL,
                stage_sales_returns_pl_document_ids     JSON         DEFAULT NULL,
                stage_sales_returns_preflight_snapshot  JSON         DEFAULT NULL,

                stage_costs_status              VARCHAR(20)  NOT NULL DEFAULT 'pending',
                stage_costs_closed_at           TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                stage_costs_closed_by_user_id   UUID         DEFAULT NULL,
                stage_costs_pl_document_ids     JSON         DEFAULT NULL,
                stage_costs_preflight_snapshot  JSON         DEFAULT NULL,

                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,

                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_month_closes.stage_sales_returns_closed_at
                IS '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_month_closes.stage_costs_closed_at
                IS '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_month_closes.created_at
                IS '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_month_closes.updated_at
                IS '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_month_close
                ON marketplace_month_closes (company_id, marketplace, year, month)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_month_close_lookup
                ON marketplace_month_closes (company_id, marketplace, year, month)
        SQL);

        // --- marketplace_cost_pl_mappings ---
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_cost_pl_mappings (
                id               UUID        NOT NULL,
                company_id       UUID        NOT NULL,
                cost_category_id UUID        NOT NULL,
                pl_category_id   UUID        DEFAULT NULL,
                include_in_pl    BOOLEAN     NOT NULL DEFAULT true,
                is_negative      BOOLEAN     NOT NULL DEFAULT true,
                sort_order       SMALLINT    NOT NULL DEFAULT 0,
                created_at       TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at       TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_cost_pl_mappings.created_at
                IS '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_cost_pl_mappings.updated_at
                IS '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_cost_pl_mapping
                ON marketplace_cost_pl_mappings (company_id, cost_category_id)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_cost_pl_mapping_company
                ON marketplace_cost_pl_mappings (company_id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_cost_pl_mappings
                ADD CONSTRAINT fk_cost_pl_mapping_category
                    FOREIGN KEY (cost_category_id)
                        REFERENCES marketplace_cost_categories (id)
                        ON DELETE CASCADE
                        NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS marketplace_cost_pl_mappings');
        $this->addSql('DROP TABLE IF EXISTS marketplace_month_closes');
    }
}
