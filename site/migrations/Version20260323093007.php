<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323093007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog: remove purchase_price from products, drop FK company from product_purchase_prices, add product_barcodes, product_imports, product_import_sequences, add internal_article + vendor_sku to products';
    }

    public function up(Schema $schema): void
    {
        // 1. Удаляем денормализованное поле purchase_price из products
        $this->addSql('ALTER TABLE products DROP COLUMN purchase_price');

        // 2. Новые поля в products
        $this->addSql('ALTER TABLE products ADD COLUMN internal_article VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN vendor_sku VARCHAR(150) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_internal_article ON products (company_id, internal_article)');

        // 3. Убираем FK на Company в product_purchase_prices — plain UUID string
        $this->addSql('ALTER TABLE product_purchase_prices DROP CONSTRAINT fk_purchase_price_company');

        // 4. Счётчик для генерации внутренних артикулов PRD-{YYYY}-{NNNNNN}
        $this->addSql('
            CREATE TABLE product_import_sequences (
                company_id UUID     NOT NULL,
                year       SMALLINT NOT NULL,
                last_seq   INTEGER  NOT NULL DEFAULT 0,
                PRIMARY KEY (company_id, year)
            )
        ');

        // 5. Баркоды товаров (append-only, один товар — много баркодов)
        $this->addSql('
            CREATE TABLE product_barcodes (
                id         UUID         NOT NULL,
                company_id UUID         NOT NULL,
                product_id UUID         NOT NULL,
                barcode    VARCHAR(100) NOT NULL,
                type       VARCHAR(20)  NOT NULL DEFAULT \'EAN13\',
                is_primary BOOLEAN      NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_product_barcodes_product
                    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_barcode_company ON product_barcodes (company_id, barcode)');
        $this->addSql('CREATE INDEX idx_product_barcodes_product ON product_barcodes (product_id)');
        $this->addSql("COMMENT ON COLUMN product_barcodes.created_at IS '(DC2Type:datetime_immutable)'");

        // 6. История импортов файлов
        $this->addSql('
            CREATE TABLE product_imports (
                id            UUID         NOT NULL,
                company_id    UUID         NOT NULL,
                file_path     VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                status        VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                rows_total    INTEGER      DEFAULT NULL,
                rows_created  INTEGER      DEFAULT NULL,
                rows_skipped  INTEGER      DEFAULT NULL,
                result_json   JSON         DEFAULT NULL,
                created_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                finished_at   TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        ');
        $this->addSql('CREATE INDEX idx_product_imports_company ON product_imports (company_id)');
        $this->addSql('CREATE INDEX idx_product_imports_status ON product_imports (status)');
        $this->addSql("COMMENT ON COLUMN product_imports.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN product_imports.finished_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_imports');
        $this->addSql('DROP TABLE product_barcodes');
        $this->addSql('DROP TABLE product_import_sequences');
        $this->addSql('DROP INDEX uniq_company_internal_article');
        $this->addSql('ALTER TABLE products DROP COLUMN vendor_sku');
        $this->addSql('ALTER TABLE products DROP COLUMN internal_article');
        $this->addSql('ALTER TABLE products ADD COLUMN purchase_price NUMERIC(10, 2) NOT NULL DEFAULT 0');
    }
}
