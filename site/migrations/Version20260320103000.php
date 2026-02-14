<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add company/is_transfer index for snapshot aggregate queries on cash transactions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_cash_transaction_company_is_transfer ON cash_transaction (company_id, is_transfer)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cash_transaction_company_is_transfer');
    }
}
