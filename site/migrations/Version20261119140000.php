<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track copies and links between company project directories';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_project_direction_company_id ON project_directions (company_id, id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE project_direction_copy_mappings (
                source_project_id UUID NOT NULL,
                target_company_id UUID NOT NULL,
                target_project_id UUID NOT NULL,
                PRIMARY KEY (source_project_id, target_company_id),
                CONSTRAINT fk_project_copy_mapping_source FOREIGN KEY (source_project_id)
                    REFERENCES project_directions (id) ON DELETE CASCADE,
                CONSTRAINT fk_project_copy_mapping_target FOREIGN KEY (target_company_id, target_project_id)
                    REFERENCES project_directions (company_id, id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_project_copy_mapping_target ON project_direction_copy_mappings (target_company_id, target_project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE project_direction_copy_mappings');
        $this->addSql('DROP INDEX uniq_project_direction_company_id');
    }
}
