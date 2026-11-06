<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Журнал запусков ИИ по компаниям (docs/tasks/ai-observability-budget, этап 2).
 *
 * Новая пустая таблица; существующие данные не затрагиваются; обратима.
 */
final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ServiceGateway: ai_run (журнал запусков ИИ)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_run (id UUID NOT NULL, company_id UUID NOT NULL, task_type VARCHAR(64) NOT NULL, ran_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, requested INT NOT NULL, tasks INT NOT NULL, redispatched INT NOT NULL, expired INT NOT NULL, skipped_reason VARCHAR(32) DEFAULT NULL, error VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ai_run_company_ran ON ai_run (company_id, ran_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_run');
    }
}
