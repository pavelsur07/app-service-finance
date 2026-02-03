<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add counterparty to deals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deals ADD counterparty_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_deal_company_counterparty ON deals (company_id, counterparty_id)');
        $this->addSql('ALTER TABLE deals ADD CONSTRAINT FK_DEALS_COUNTERPARTY FOREIGN KEY (counterparty_id) REFERENCES counterparty (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deals DROP CONSTRAINT FK_DEALS_COUNTERPARTY');
        $this->addSql('DROP INDEX idx_deal_company_counterparty');
        $this->addSql('ALTER TABLE deals DROP counterparty_id');
    }
}
