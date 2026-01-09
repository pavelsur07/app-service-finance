<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project direction references to documents and document operations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents ADD project_direction_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_documents_project_direction ON documents (project_direction_id)');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT fk_documents_project_direction FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE document_operations ADD project_direction_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_doc_oper_project_direction ON document_operations (project_direction_id)');
        $this->addSql('ALTER TABLE document_operations ADD CONSTRAINT fk_doc_oper_project_direction FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_operations DROP CONSTRAINT fk_doc_oper_project_direction');
        $this->addSql('DROP INDEX idx_doc_oper_project_direction');
        $this->addSql('ALTER TABLE document_operations DROP COLUMN project_direction_id');

        $this->addSql('ALTER TABLE documents DROP CONSTRAINT fk_documents_project_direction');
        $this->addSql('DROP INDEX idx_documents_project_direction');
        $this->addSql('ALTER TABLE documents DROP COLUMN project_direction_id');
    }
}
