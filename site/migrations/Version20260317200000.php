<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add soft delete columns to cash transactions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_transaction ADD deleted_by VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_transaction ADD delete_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN cash_transaction.deleted_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_cash_transaction_company_deleted_at ON cash_transaction (company_id, deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cash_transaction_company_deleted_at');
        $this->addSql('ALTER TABLE cash_transaction DROP deleted_at');
        $this->addSql('ALTER TABLE cash_transaction DROP deleted_by');
        $this->addSql('ALTER TABLE cash_transaction DROP delete_reason');
    }
}
