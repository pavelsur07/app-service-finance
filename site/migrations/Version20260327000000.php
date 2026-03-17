<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_ozon_realizations table for denormalized Ozon realization report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ozon_realizations (
                id                        UUID           NOT NULL,
                company_id                UUID           NOT NULL,
                listing_id                UUID           DEFAULT NULL,
                raw_document_id           UUID           NOT NULL,
                sku                       VARCHAR(50)    NOT NULL,
                offer_id                  VARCHAR(100)   DEFAULT NULL,
                name                      VARCHAR(500)   DEFAULT NULL,
                seller_price_per_instance NUMERIC(12, 2) NOT NULL,
                quantity                  INTEGER        NOT NULL,
                total_amount              NUMERIC(12, 2) NOT NULL,
                period_from               DATE           NOT NULL,
                period_to                 DATE           NOT NULL,
                pl_document_id            UUID           DEFAULT NULL,
                created_at                TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_ozon_realizations.period_from
                IS '(DC2Type:date_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_ozon_realizations.period_to
                IS '(DC2Type:date_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_ozon_realizations.created_at
                IS '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_ozon_realization_period
                ON marketplace_ozon_realizations (company_id, period_from, period_to)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_ozon_realization_pl_doc
                ON marketplace_ozon_realizations (company_id, pl_document_id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_ozon_realizations
                ADD CONSTRAINT fk_ozon_realization_raw_doc
                    FOREIGN KEY (raw_document_id)
                        REFERENCES marketplace_raw_documents (id)
                        ON DELETE CASCADE
                        NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_ozon_realizations
                ADD CONSTRAINT fk_ozon_realization_listing
                    FOREIGN KEY (listing_id)
                        REFERENCES marketplace_listings (id)
                        ON DELETE SET NULL
                        NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS marketplace_ozon_realizations');
    }
}
