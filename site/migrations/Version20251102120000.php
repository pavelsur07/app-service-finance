<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251102120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Adjust cash_transaction external_id indexes for multi-tenant uniqueness';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS uniq_cash_transaction_external_id');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS uniq_tx_company_account_external');
        $this->addSql('CREATE UNIQUE INDEX CONCURRENTLY ux_cash_tx_company_external ON cash_transaction (company_id, external_id) WHERE external_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS ux_cash_tx_company_external');
        $this->addSql('CREATE UNIQUE INDEX CONCURRENTLY uniq_tx_company_account_external ON cash_transaction (company_id, money_account_id, external_id) WHERE external_id IS NOT NULL');
    }
}
