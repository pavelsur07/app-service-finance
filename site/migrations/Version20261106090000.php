<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Учёт результатов ai-service и их стоимости (docs/tasks/ai-observability-budget, этап 1).
 *
 * Новая пустая таблица; существующие данные не затрагиваются; обратима.
 */
final class Version20261106090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ServiceGateway: ai_usage (токены и стоимость результатов ИИ)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_usage (id UUID NOT NULL, task_id UUID NOT NULL, company_id UUID NOT NULL, task_type VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, model VARCHAR(128) DEFAULT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, cost_rub NUMERIC(12, 4) DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_ai_usage_task ON ai_usage (task_id)');
        $this->addSql('CREATE INDEX idx_ai_usage_company_completed ON ai_usage (company_id, completed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_usage');
    }
}
