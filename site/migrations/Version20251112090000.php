<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251112090000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Drop import_external_id and enforce uniqueness on (company_id, import_source, external_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS ux_cash_tx_company_external');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS uniq_cashflow_import');
        $this->addSql('ALTER TABLE cash_transaction DROP import_external_id');
        $this->addSql('CREATE UNIQUE INDEX CONCURRENTLY uniq_cashflow_import ON cash_transaction (company_id, import_source, external_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS uniq_cashflow_import');
        $this->addSql('ALTER TABLE cash_transaction ADD import_external_id VARCHAR(128)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cashflow_import ON cash_transaction (company_id, import_source, import_external_id)');
        $this->addSql('CREATE UNIQUE INDEX CONCURRENTLY ux_cash_tx_company_external ON cash_transaction (company_id, external_id) WHERE external_id IS NOT NULL');
    }
}
