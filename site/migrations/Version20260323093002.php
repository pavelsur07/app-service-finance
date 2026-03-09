<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323093002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove company FK from marketplace_listing_barcodes, keep company_id as plain uuid column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listing_barcodes DROP CONSTRAINT fk_barcode_company');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('
            ALTER TABLE marketplace_listing_barcodes
            ADD CONSTRAINT fk_barcode_company
            FOREIGN KEY (company_id)
            REFERENCES companies (id)
            ON DELETE RESTRICT
            NOT DEFERRABLE INITIALLY IMMEDIATE
        ');
    }
}
