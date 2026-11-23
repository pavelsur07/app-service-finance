<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company: company_consolidation_member — группа консолидации (строка головной: head_company_id = company_id).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE company_consolidation_member (company_id UUID NOT NULL, head_company_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id))');
        $this->addSql('CREATE INDEX idx_company_consolidation_member_head ON company_consolidation_member (head_company_id)');
        $this->addSql('ALTER TABLE company_consolidation_member ADD CONSTRAINT fk_company_consolidation_member_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE company_consolidation_member ADD CONSTRAINT fk_company_consolidation_member_head FOREIGN KEY (head_company_id) REFERENCES companies (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE company_consolidation_member');
    }
}
