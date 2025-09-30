<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create PL daily totals table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE pl_daily_totals (id UUID NOT NULL, company_id UUID NOT NULL, pl_category_id UUID DEFAULT NULL, date DATE NOT NULL, amount_income NUMERIC(18, 2) NOT NULL, amount_expense NUMERIC(18, 2) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_pl_daily_company_cat_date ON pl_daily_totals (company_id, pl_category_id, date)');
        $this->addSql('CREATE INDEX idx_pl_daily_company_date ON pl_daily_totals (company_id, date)');
        $this->addSql('CREATE INDEX idx_pl_daily_company_cat_date ON pl_daily_totals (company_id, pl_category_id, date)');

        if ('postgresql' === $this->connection->getDatabasePlatform()->getName()) {
            $this->addSql('ALTER TABLE pl_daily_totals ADD CONSTRAINT chk_pl_daily_totals_amounts CHECK (amount_income >= 0 AND amount_expense >= 0)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pl_daily_totals');
    }
}
