<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323093001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_listing_barcodes table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE marketplace_listing_barcodes (
                id UUID NOT NULL,
                listing_id UUID NOT NULL,
                company_id UUID NOT NULL,
                barcode VARCHAR(100) NOT NULL,
                PRIMARY KEY(id)
            )
        ');

        $this->addSql('
            CREATE UNIQUE INDEX uniq_company_barcode
            ON marketplace_listing_barcodes (company_id, barcode)
        ');

        $this->addSql('
            CREATE INDEX idx_listing_barcodes
            ON marketplace_listing_barcodes (listing_id)
        ');

        $this->addSql('
            ALTER TABLE marketplace_listing_barcodes
            ADD CONSTRAINT fk_barcode_listing
            FOREIGN KEY (listing_id)
            REFERENCES marketplace_listings (id)
            ON DELETE CASCADE
            NOT DEFERRABLE INITIALLY IMMEDIATE
        ');

        $this->addSql('
            ALTER TABLE marketplace_listing_barcodes
            ADD CONSTRAINT fk_barcode_company
            FOREIGN KEY (company_id)
            REFERENCES companies (id)
            ON DELETE RESTRICT
            NOT DEFERRABLE INITIALLY IMMEDIATE
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_listing_barcodes');
    }
}
