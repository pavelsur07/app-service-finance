<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250908131500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index for externalId';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_tx_company_account_external ON cash_transaction (company_id, money_account_id, external_id) WHERE external_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_tx_company_account_external');
    }
}
