<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CashTransaction: add hasViolatedDocument field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE cash_transaction ADD has_violated_document BOOLEAN NOT NULL DEFAULT false
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE cash_transaction DROP COLUMN has_violated_document
        SQL);
    }
}
