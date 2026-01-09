<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wildberries_sales table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "wildberries_sales" (
    id UUID NOT NULL,
    company_id UUID NOT NULL,
    srid VARCHAR(64) NOT NULL,
    sold_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    supplier_article VARCHAR(255) DEFAULT NULL,
    tech_size VARCHAR(255) DEFAULT NULL,
    barcode VARCHAR(255) DEFAULT NULL,
    quantity INT NOT NULL,
    price NUMERIC(15, 2) NOT NULL,
    finished_price NUMERIC(15, 2) NOT NULL,
    for_pay NUMERIC(15, 2) DEFAULT NULL,
    delivery_amount NUMERIC(15, 2) DEFAULT NULL,
    order_type VARCHAR(255) DEFAULT NULL,
    sale_status VARCHAR(255) DEFAULT NULL,
    warehouse_name VARCHAR(255) DEFAULT NULL,
    oblast VARCHAR(255) DEFAULT NULL,
    odid VARCHAR(255) DEFAULT NULL,
    sale_id VARCHAR(255) DEFAULT NULL,
    status_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    raw JSON NOT NULL,
    PRIMARY KEY(id)
)');
        $this->addSql('CREATE INDEX IDX_WB_SALES_COMPANY ON "wildberries_sales" (company_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_WB_SALES_COMPANY_SRID ON "wildberries_sales" (company_id, srid)');
        $this->addSql('COMMENT ON COLUMN "wildberries_sales".sold_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "wildberries_sales".status_updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE "wildberries_sales" ADD CONSTRAINT FK_WB_SALES_COMPANY FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "wildberries_sales" DROP CONSTRAINT FK_WB_SALES_COMPANY');
        $this->addSql('DROP INDEX IDX_WB_SALES_COMPANY');
        $this->addSql('DROP INDEX UNIQ_WB_SALES_COMPANY_SRID');
        $this->addSql('DROP TABLE "wildberries_sales"');
    }
}
