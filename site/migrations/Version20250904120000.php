<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create counterparty table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "counterparty" (id UUID NOT NULL, company_id UUID NOT NULL, name VARCHAR(255) NOT NULL, inn VARCHAR(12) DEFAULT NULL, type VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, is_archived BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_counterparty_company ON "counterparty" (company_id)');
        $this->addSql('CREATE INDEX idx_counterparty_company_inn ON "counterparty" (company_id, inn)');
        $this->addSql('CREATE INDEX idx_counterparty_company_name ON "counterparty" (company_id, LOWER(name))');
        $this->addSql('CREATE UNIQUE INDEX uniq_counterparty_company_inn ON "counterparty" (company_id, inn) WHERE inn IS NOT NULL');
        $this->addSql('ALTER TABLE "counterparty" ADD CONSTRAINT FK_counterparty_company FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "counterparty" DROP CONSTRAINT FK_counterparty_company');
        $this->addSql('DROP TABLE "counterparty"');
    }
}
