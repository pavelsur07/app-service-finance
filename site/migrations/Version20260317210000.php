<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, company_id UUID NOT NULL, entity_class VARCHAR(255) NOT NULL, entity_id VARCHAR(255) NOT NULL, action VARCHAR(16) NOT NULL, diff JSON DEFAULT NULL, actor_user_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_audit_log_company_created_at ON audit_log (company_id, created_at)');
        $this->addSql('CREATE INDEX idx_audit_log_entity_created_at ON audit_log (entity_class, entity_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_log');
    }
}
