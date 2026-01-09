<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250908132000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create auto_category_template and auto_category_condition tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE auto_category_template (id UUID NOT NULL, company_id UUID NOT NULL, target_category_id UUID DEFAULT NULL, name VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, direction VARCHAR(255) NOT NULL, priority INT NOT NULL, stop_on_match BOOLEAN NOT NULL, match_logic VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_AC_TEMPLATE_COMPANY ON auto_category_template (company_id)');
        $this->addSql('CREATE INDEX IDX_AC_TEMPLATE_CATEGORY ON auto_category_template (target_category_id)');

        $this->addSql('CREATE TABLE auto_category_condition (id UUID NOT NULL, template_id UUID NOT NULL, field VARCHAR(255) NOT NULL, operator VARCHAR(255) NOT NULL, value TEXT NOT NULL, case_sensitive BOOLEAN NOT NULL, negate BOOLEAN NOT NULL, position INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_AC_CONDITION_TEMPLATE ON auto_category_condition (template_id)');

        $this->addSql('ALTER TABLE auto_category_template ADD CONSTRAINT FK_AC_TEMPLATE_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE auto_category_template ADD CONSTRAINT FK_AC_TEMPLATE_CATEGORY FOREIGN KEY (target_category_id) REFERENCES "cashflow_categories" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE auto_category_condition ADD CONSTRAINT FK_AC_CONDITION_TEMPLATE FOREIGN KEY (template_id) REFERENCES auto_category_template (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auto_category_condition DROP CONSTRAINT FK_AC_CONDITION_TEMPLATE');
        $this->addSql('ALTER TABLE auto_category_template DROP CONSTRAINT FK_AC_TEMPLATE_COMPANY');
        $this->addSql('ALTER TABLE auto_category_template DROP CONSTRAINT FK_AC_TEMPLATE_CATEGORY');
        $this->addSql('DROP TABLE auto_category_condition');
        $this->addSql('DROP TABLE auto_category_template');
    }
}
