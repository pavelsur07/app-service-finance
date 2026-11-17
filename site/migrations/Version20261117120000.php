<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash: cashflow_categories.group_code — ключ консолидации (код узла базового дерева), unique в компании.';
    }

    public function up(Schema $schema): void
    {
        // NULL — у всех существующих статей; коды ставит онбординг и app:cash:cashflow-core:apply.
        $this->addSql('ALTER TABLE "cashflow_categories" ADD group_code VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_cashflow_category_company_group_code ON "cashflow_categories" (company_id, group_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cashflow_category_company_group_code');
        $this->addSql('ALTER TABLE "cashflow_categories" DROP group_code');
    }
}
