<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import source fields to cash_transaction and unique constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction ADD import_source VARCHAR(32)');
        $this->addSql('ALTER TABLE cash_transaction ADD import_external_id VARCHAR(128)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cashflow_import ON cash_transaction (company_id, import_source, import_external_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cashflow_import');
        $this->addSql('ALTER TABLE cash_transaction DROP import_source');
        $this->addSql('ALTER TABLE cash_transaction DROP import_external_id');
    }
}
