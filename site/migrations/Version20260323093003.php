<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323093003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_barcode_catalog table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE marketplace_barcode_catalog (
                id          UUID         NOT NULL,
                company_id  UUID         NOT NULL,
                marketplace VARCHAR(50)  NOT NULL,
                external_id VARCHAR(100) NOT NULL,
                barcode     VARCHAR(100) NOT NULL,
                size        VARCHAR(50)  NOT NULL,
                PRIMARY KEY (id)
            )
        ');

        $this->addSql('
            CREATE UNIQUE INDEX uniq_company_marketplace_barcode
            ON marketplace_barcode_catalog (company_id, marketplace, barcode)
        ');

        $this->addSql('
            CREATE INDEX idx_barcode_catalog_external
            ON marketplace_barcode_catalog (company_id, marketplace, external_id)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_barcode_catalog');
    }
}
