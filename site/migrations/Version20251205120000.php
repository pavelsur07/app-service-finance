<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251205120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add P&L categories and principal flag to finance_loan';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_loan ADD pl_category_interest_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE finance_loan ADD pl_category_fee_id UUID DEFAULT NULL');
        $this->addSql("ALTER TABLE finance_loan ADD include_principal_in_pnl BOOLEAN DEFAULT false NOT NULL");
        $this->addSql('ALTER TABLE finance_loan ADD CONSTRAINT FK_FINANCE_LOAN_PL_CATEGORY_INTEREST FOREIGN KEY (pl_category_interest_id) REFERENCES pl_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_loan ADD CONSTRAINT FK_FINANCE_LOAN_PL_CATEGORY_FEE FOREIGN KEY (pl_category_fee_id) REFERENCES pl_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_loan DROP CONSTRAINT FK_FINANCE_LOAN_PL_CATEGORY_INTEREST');
        $this->addSql('ALTER TABLE finance_loan DROP CONSTRAINT FK_FINANCE_LOAN_PL_CATEGORY_FEE');
        $this->addSql('ALTER TABLE finance_loan DROP COLUMN pl_category_interest_id');
        $this->addSql('ALTER TABLE finance_loan DROP COLUMN pl_category_fee_id');
        $this->addSql('ALTER TABLE finance_loan DROP COLUMN include_principal_in_pnl');
    }
}
