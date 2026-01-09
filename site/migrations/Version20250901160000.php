<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250901160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cashflow categories table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "cashflow_categories" (id UUID NOT NULL, parent_id UUID DEFAULT NULL, company_id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, sort INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CFC_PARENT ON "cashflow_categories" (parent_id)');
        $this->addSql('CREATE INDEX IDX_CFC_COMPANY ON "cashflow_categories" (company_id)');
        $this->addSql('ALTER TABLE "cashflow_categories" ADD CONSTRAINT FK_CFC_PARENT FOREIGN KEY (parent_id) REFERENCES "cashflow_categories" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "cashflow_categories" ADD CONSTRAINT FK_CFC_COMPANY FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "cashflow_categories" DROP CONSTRAINT FK_CFC_PARENT');
        $this->addSql('ALTER TABLE "cashflow_categories" DROP CONSTRAINT FK_CFC_COMPANY');
        $this->addSql('DROP TABLE "cashflow_categories"');
    }
}
