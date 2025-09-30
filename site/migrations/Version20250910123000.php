<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250910123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create PL monthly snapshots table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE pl_monthly_snapshots (id UUID NOT NULL, company_id UUID NOT NULL, pl_category_id UUID DEFAULT NULL, period VARCHAR(7) NOT NULL, amount_income NUMERIC(18, 2) NOT NULL, amount_expense NUMERIC(18, 2) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_pl_monthly_company_cat_period ON pl_monthly_snapshots (company_id, pl_category_id, period)');
        $this->addSql('CREATE INDEX idx_pl_monthly_company_period ON pl_monthly_snapshots (company_id, period)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pl_monthly_snapshots');
    }
}
