<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recreate marketplace_inventory_cost_prices: listing_id instead of product_id + marketplace (cost tied to listing, not product)';
    }

    public function up(Schema $schema): void
    {
        // Дропаем старую таблицу если существует
        $this->addSql('DROP TABLE IF EXISTS marketplace_inventory_cost_prices');

        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_inventory_cost_prices (
                id             UUID           NOT NULL,
                company_id     UUID           NOT NULL,
                listing_id     UUID           NOT NULL,
                effective_from DATE           NOT NULL,
                effective_to   DATE           DEFAULT NULL,
                price_amount   NUMERIC(10, 2) NOT NULL,
                price_currency VARCHAR(3)     NOT NULL DEFAULT 'RUB',
                note           VARCHAR(255)   DEFAULT NULL,
                created_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_inventory_cost_prices.effective_from
                IS '(DC2Type:date_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_inventory_cost_prices.effective_to
                IS '(DC2Type:date_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_inventory_cost_prices.created_at
                IS '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_inv_cost_company_listing_from
                ON marketplace_inventory_cost_prices (company_id, listing_id, effective_from)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_inv_cost_company_listing_to
                ON marketplace_inventory_cost_prices (company_id, listing_id, effective_to)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_inventory_cost_prices
                ADD CONSTRAINT uniq_inv_cost_listing_from
                    UNIQUE (listing_id, effective_from)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_inventory_cost_prices
                ADD CONSTRAINT fk_inv_cost_listing
                    FOREIGN KEY (listing_id)
                        REFERENCES marketplace_listings (id)
                        ON DELETE CASCADE
                        NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS marketplace_inventory_cost_prices');
    }
}
