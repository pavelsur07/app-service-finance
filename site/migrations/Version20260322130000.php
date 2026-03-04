<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace_sale_mappings table for MarketplaceSaleMapping entity';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('marketplace_sale_mappings')) {
            return;
        }

        $this->addSql('CREATE TABLE marketplace_sale_mappings (id UUID NOT NULL, company_id UUID NOT NULL, pl_category_id UUID NOT NULL, project_direction_id UUID DEFAULT NULL, marketplace VARCHAR(255) NOT NULL, operation_type VARCHAR(10) NOT NULL, amount_source VARCHAR(255) NOT NULL, is_negative BOOLEAN DEFAULT FALSE NOT NULL, description_template VARCHAR(255) DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, is_active BOOLEAN DEFAULT TRUE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_sale_mapping ON marketplace_sale_mappings (company_id, marketplace, operation_type, amount_source, pl_category_id)');
        $this->addSql('CREATE INDEX idx_mapping_lookup ON marketplace_sale_mappings (company_id, marketplace, operation_type)');
        $this->addSql('CREATE INDEX idx_mapping_project_direction ON marketplace_sale_mappings (project_direction_id)');

        if ($schema->hasTable('companies')) {
            $this->addSql('ALTER TABLE marketplace_sale_mappings ADD CONSTRAINT fk_sale_mapping_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        if ($schema->hasTable('pl_categories')) {
            $this->addSql('ALTER TABLE marketplace_sale_mappings ADD CONSTRAINT fk_sale_mapping_pl_category FOREIGN KEY (pl_category_id) REFERENCES pl_categories (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        if ($schema->hasTable('project_directions')) {
            $this->addSql('ALTER TABLE marketplace_sale_mappings ADD CONSTRAINT fk_sale_mapping_project_direction FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_sale_mappings')) {
            return;
        }

        $this->addSql('ALTER TABLE marketplace_sale_mappings DROP CONSTRAINT IF EXISTS fk_sale_mapping_project_direction');
        $this->addSql('ALTER TABLE marketplace_sale_mappings DROP CONSTRAINT IF EXISTS fk_sale_mapping_pl_category');
        $this->addSql('ALTER TABLE marketplace_sale_mappings DROP CONSTRAINT IF EXISTS fk_sale_mapping_company');
        $this->addSql('DROP TABLE marketplace_sale_mappings');
    }
}
