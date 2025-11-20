<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251128120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link documents to cash transactions and track allocated amounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction ADD allocated_amount NUMERIC(18, 2) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE documents ADD cash_transaction_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_DOCUMENT_CASH_TRANSACTION FOREIGN KEY (cash_transaction_id) REFERENCES cash_transaction (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_DOCUMENTS_CASH_TRANSACTION ON documents (cash_transaction_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_DOCUMENTS_CASH_TRANSACTION');
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT IF EXISTS FK_DOCUMENT_CASH_TRANSACTION');
        $this->addSql('ALTER TABLE documents DROP cash_transaction_id');
        $this->addSql('ALTER TABLE cash_transaction DROP allocated_amount');
    }
}
