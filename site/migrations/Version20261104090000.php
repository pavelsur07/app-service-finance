<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Бюджет ОПиУ (docs/tasks/pl-budget, этап 0): варианты и плановые суммы.
 *
 * Новые пустые таблицы, данные не затрагиваются; обратима. Частичный unique и внешние ключи на
 * `companies` описаны в BudgetSchemaExtension.
 */
final class Version20261104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Budget: budget_versions and budget_lines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE budget_versions (id UUID NOT NULL, company_id UUID NOT NULL, year SMALLINT NOT NULL, name VARCHAR(120) NOT NULL, status VARCHAR(16) NOT NULL, copied_from_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_budget_versions_company_year ON budget_versions (company_id, year)');
        $this->addSql("CREATE UNIQUE INDEX uniq_budget_versions_approved_year ON budget_versions (company_id, year) WHERE ((status)::text = 'approved'::text)");
        $this->addSql('ALTER TABLE budget_versions ADD CONSTRAINT fk_budget_versions_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE budget_lines (id UUID NOT NULL, company_id UUID NOT NULL, pl_category_id UUID NOT NULL, month DATE NOT NULL, project_direction_id UUID NOT NULL, responsibility_center_id UUID NOT NULL, amount NUMERIC(18, 2) NOT NULL, source VARCHAR(16) NOT NULL, comment TEXT DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_budget_lines_company ON budget_lines (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_budget_lines_cell ON budget_lines (version_id, pl_category_id, month, project_direction_id, responsibility_center_id)');
        $this->addSql('CREATE INDEX IDX_241019604BBC2705 ON budget_lines (version_id)');
        $this->addSql('ALTER TABLE budget_lines ADD CONSTRAINT FK_241019604BBC2705 FOREIGN KEY (version_id) REFERENCES budget_versions (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE budget_lines ADD CONSTRAINT fk_budget_lines_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE budget_lines');
        $this->addSql('DROP TABLE budget_versions');
    }
}
