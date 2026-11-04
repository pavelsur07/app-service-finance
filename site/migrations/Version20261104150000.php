<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Бюджет ОПиУ (docs/tasks/pl-budget, этап 8): правила «X % от строки-базы».
 *
 * Новая пустая таблица, данные не затрагиваются; обратима. Ключ на `companies` описан в
 * BudgetSchemaExtension.
 */
final class Version20261104150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Budget: budget_rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE budget_rules (id UUID NOT NULL, company_id UUID NOT NULL, pl_category_id UUID NOT NULL, project_direction_id UUID NOT NULL, responsibility_center_id UUID NOT NULL, base_category_id UUID NOT NULL, percent NUMERIC(6, 2) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_budget_rules_company ON budget_rules (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_budget_rules_target ON budget_rules (version_id, pl_category_id, project_direction_id, responsibility_center_id)');
        $this->addSql('CREATE INDEX IDX_E28B0CCA4BBC2705 ON budget_rules (version_id)');
        $this->addSql('ALTER TABLE budget_rules ADD CONSTRAINT FK_E28B0CCA4BBC2705 FOREIGN KEY (version_id) REFERENCES budget_versions (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE budget_rules ADD CONSTRAINT fk_budget_rules_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE budget_rules');
    }
}
