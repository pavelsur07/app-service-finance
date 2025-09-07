<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250908000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ozon orders tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ozon_orders (id UUID NOT NULL, company_id UUID NOT NULL, posting_number VARCHAR(255) NOT NULL, scheme VARCHAR(3) NOT NULL, status VARCHAR(255) NOT NULL, status_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ozon_created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ozon_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, warehouse_id BIGINT DEFAULT NULL, delivery_method_name VARCHAR(255) DEFAULT NULL, payment_status VARCHAR(255) DEFAULT NULL, raw JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_posting ON ozon_orders (company_id, posting_number)');
        $this->addSql('CREATE INDEX idx_scheme ON ozon_orders (scheme)');
        $this->addSql('CREATE INDEX idx_status ON ozon_orders (status)');
        $this->addSql('CREATE INDEX idx_ozon_updated_at ON ozon_orders (ozon_updated_at)');

        $this->addSql('CREATE TABLE ozon_order_items (id UUID NOT NULL, order_id UUID NOT NULL, sku BIGINT DEFAULT NULL, offer_id VARCHAR(255) DEFAULT NULL, quantity INT NOT NULL, price NUMERIC(12, 2) NOT NULL, product_id UUID DEFAULT NULL, raw JSONB NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_product ON ozon_order_items (product_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_order_item ON ozon_order_items (order_id, sku, offer_id)');

        $this->addSql('CREATE TABLE ozon_order_status_history (id UUID NOT NULL, order_id UUID NOT NULL, status VARCHAR(255) NOT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, raw_event JSONB NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_order_status_changed ON ozon_order_status_history (order_id, status, changed_at)');
        $this->addSql('CREATE INDEX idx_order_changed ON ozon_order_status_history (order_id, changed_at)');
        $this->addSql('CREATE INDEX idx_status_history_status ON ozon_order_status_history (status)');

        $this->addSql('CREATE TABLE ozon_sync_cursor (id UUID NOT NULL, company_id UUID NOT NULL, scheme VARCHAR(3) NOT NULL, last_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_to TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_scheme ON ozon_sync_cursor (company_id, scheme)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ozon_order_items');
        $this->addSql('DROP TABLE ozon_order_status_history');
        $this->addSql('DROP TABLE ozon_orders');
        $this->addSql('DROP TABLE ozon_sync_cursor');
    }
}
