<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250727000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ozon product stock and sales tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "ozon_product_stocks" (id UUID NOT NULL, product_id UUID NOT NULL, company_id UUID NOT NULL, qty INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_OZON_PRODUCT_STOCK_PRODUCT ON "ozon_product_stocks" (product_id)');
        $this->addSql('CREATE INDEX IDX_OZON_PRODUCT_STOCK_COMPANY ON "ozon_product_stocks" (company_id)');
        $this->addSql('COMMENT ON COLUMN "ozon_product_stocks".updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE "ozon_product_stocks" ADD CONSTRAINT FK_OZON_STOCK_PRODUCT FOREIGN KEY (product_id) REFERENCES "ozon_products" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "ozon_product_stocks" ADD CONSTRAINT FK_OZON_STOCK_COMPANY FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE "ozon_product_sales" (id UUID NOT NULL, product_id UUID NOT NULL, company_id UUID NOT NULL, qty INT NOT NULL, date_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, date_to TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_OZON_PRODUCT_SALES_PRODUCT ON "ozon_product_sales" (product_id)');
        $this->addSql('CREATE INDEX IDX_OZON_PRODUCT_SALES_COMPANY ON "ozon_product_sales" (company_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_OZON_PRODUCT_SALES_PERIOD ON "ozon_product_sales" (product_id, company_id, date_from, date_to)');
        $this->addSql('COMMENT ON COLUMN "ozon_product_sales".date_from IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "ozon_product_sales".date_to IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE "ozon_product_sales" ADD CONSTRAINT FK_OZON_SALES_PRODUCT FOREIGN KEY (product_id) REFERENCES "ozon_products" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "ozon_product_sales" ADD CONSTRAINT FK_OZON_SALES_COMPANY FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "ozon_product_stocks" DROP CONSTRAINT FK_OZON_STOCK_PRODUCT');
        $this->addSql('ALTER TABLE "ozon_product_stocks" DROP CONSTRAINT FK_OZON_STOCK_COMPANY');
        $this->addSql('ALTER TABLE "ozon_product_sales" DROP CONSTRAINT FK_OZON_SALES_PRODUCT');
        $this->addSql('ALTER TABLE "ozon_product_sales" DROP CONSTRAINT FK_OZON_SALES_COMPANY');
        $this->addSql('DROP TABLE "ozon_product_stocks"');
        $this->addSql('DROP TABLE "ozon_product_sales"');
    }
}
