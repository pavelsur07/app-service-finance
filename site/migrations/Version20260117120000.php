<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add VAT fields to cash transactions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction ADD COLUMN IF NOT EXISTS vat_rate_percent SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_transaction ADD COLUMN IF NOT EXISTS vat_amount NUMERIC(18, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction DROP COLUMN IF EXISTS vat_rate_percent');
        $this->addSql('ALTER TABLE cash_transaction DROP COLUMN IF EXISTS vat_amount');
    }
}
