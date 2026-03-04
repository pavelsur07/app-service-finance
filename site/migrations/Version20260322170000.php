<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_pl to documents type CHECK constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT documents_type_enum_check');
        $this->addSql("ALTER TABLE documents ADD CONSTRAINT documents_type_enum_check CHECK (type IN ('DEAL_SALE', 'PAYROLL', 'TAXES', 'LOANS', 'OBLIGATIONS', 'ASSETS', 'CASH', 'CASHFLOW_EXPENSE', 'OTHER', 'marketplace_pl'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT documents_type_enum_check');
        $this->addSql("ALTER TABLE documents ADD CONSTRAINT documents_type_enum_check CHECK (type IN ('DEAL_SALE', 'PAYROLL', 'TAXES', 'LOANS', 'OBLIGATIONS', 'ASSETS', 'CASH', 'CASHFLOW_EXPENSE', 'OTHER'))");
    }
}
