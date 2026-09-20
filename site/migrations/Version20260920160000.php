<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tenant-scoped MoySklad products and variants with a same-connection parent FK.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moysklad_products (id UUID NOT NULL, company_id UUID NOT NULL, connection_id UUID NOT NULL, external_id UUID NOT NULL, name VARCHAR(255) NOT NULL, external_code VARCHAR(255) NOT NULL, code VARCHAR(255) DEFAULT NULL, article VARCHAR(255) DEFAULT NULL, variants_count INT NOT NULL, archived BOOLEAN NOT NULL, source_updated_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, loaded_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_moysklad_products_variants_count CHECK (variants_count >= 0))');
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_products_connection_external ON moysklad_products (connection_id, external_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_products_tenant_external ON moysklad_products (company_id, connection_id, external_id)');
        $this->addSql('ALTER TABLE moysklad_products ADD CONSTRAINT fk_moysklad_products_connection FOREIGN KEY (company_id, connection_id) REFERENCES moysklad_connections (company_id, id) ON DELETE RESTRICT');

        $this->addSql('CREATE TABLE moysklad_variants (id UUID NOT NULL, company_id UUID NOT NULL, connection_id UUID NOT NULL, external_id UUID NOT NULL, product_external_id UUID NOT NULL, name VARCHAR(255) NOT NULL, external_code VARCHAR(255) NOT NULL, code VARCHAR(255) DEFAULT NULL, article VARCHAR(255) DEFAULT NULL, characteristics JSONB NOT NULL, archived BOOLEAN NOT NULL, source_updated_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, loaded_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_variants_connection_external ON moysklad_variants (connection_id, external_id)');
        $this->addSql('CREATE INDEX idx_moysklad_variants_product ON moysklad_variants (company_id, connection_id, product_external_id)');
        $this->addSql('ALTER TABLE moysklad_variants ADD CONSTRAINT fk_moysklad_variants_connection FOREIGN KEY (company_id, connection_id) REFERENCES moysklad_connections (company_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE moysklad_variants ADD CONSTRAINT fk_moysklad_variants_product FOREIGN KEY (company_id, connection_id, product_external_id) REFERENCES moysklad_products (company_id, connection_id, external_id) ON DELETE RESTRICT');

        foreach (['moysklad_products.source_updated_at', 'moysklad_products.loaded_at', 'moysklad_variants.source_updated_at', 'moysklad_variants.loaded_at'] as $column) {
            $this->addSql(sprintf("COMMENT ON COLUMN %s IS '(DC2Type:datetime_immutable_utc_ms)'", $column));
        }
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('LOCK TABLE moysklad_products, moysklad_variants IN ACCESS EXCLUSIVE MODE');
        foreach (['moysklad_products', 'moysklad_variants'] as $table) {
            if (0 < (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table))) {
                $this->throwIrreversibleMigrationException('MoySklad catalog tables contain data; rollback requires a separate data decision.');
            }
        }
        $this->addSql('DROP TABLE moysklad_variants');
        $this->addSql('DROP TABLE moysklad_products');
    }
}
