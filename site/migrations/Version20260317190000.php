<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deal sequence table for per-company numbering';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deals_deal_sequence (company_id UUID NOT NULL, last_number INT NOT NULL, PRIMARY KEY(company_id))');
        $this->addSql('COMMENT ON COLUMN deals_deal_sequence.company_id IS \'(DC2Type:guid)\'');
        $this->addSql('ALTER TABLE deals_deal_sequence ADD CONSTRAINT FK_DEALS_DEAL_SEQUENCE_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deals_deal_sequence DROP CONSTRAINT FK_DEALS_DEAL_SEQUENCE_COMPANY');
        $this->addSql('DROP TABLE deals_deal_sequence');
    }
}
