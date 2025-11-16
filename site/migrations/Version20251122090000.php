<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251122090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create AI module tables (agents, runs, suggestions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_agent (id UUID NOT NULL, company_id UUID NOT NULL, type VARCHAR(255) NOT NULL, is_enabled BOOLEAN NOT NULL, settings JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_agent_company_type ON ai_agent (company_id, type)');
        $this->addSql('ALTER TABLE ai_agent ADD CONSTRAINT FK_2CBF7E87979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN ai_agent.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ai_agent.updated_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE TABLE ai_run (id UUID NOT NULL, company_id UUID NOT NULL, agent_id UUID NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(255) NOT NULL, input_summary TEXT DEFAULT NULL, output TEXT DEFAULT NULL, error_message TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_ai_run_company_started ON ai_run (company_id, started_at)');
        $this->addSql('ALTER TABLE ai_run ADD CONSTRAINT FK_72BD07C6979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE ai_run ADD CONSTRAINT FK_72BD07C6432B3009 FOREIGN KEY (agent_id) REFERENCES ai_agent (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN ai_run.started_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ai_run.finished_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE TABLE ai_suggestion (id UUID NOT NULL, company_id UUID NOT NULL, agent_id UUID NOT NULL, run_id UUID NOT NULL, title VARCHAR(255) NOT NULL, description TEXT NOT NULL, severity VARCHAR(255) NOT NULL, is_read BOOLEAN NOT NULL, is_applied BOOLEAN NOT NULL, related_entity_type VARCHAR(191) DEFAULT NULL, related_entity_id VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_ai_suggestion_company_created ON ai_suggestion (company_id, created_at)');
        $this->addSql('ALTER TABLE ai_suggestion ADD CONSTRAINT FK_1C7FA989979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE ai_suggestion ADD CONSTRAINT FK_1C7FA989432B3009 FOREIGN KEY (agent_id) REFERENCES ai_agent (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE ai_suggestion ADD CONSTRAINT FK_1C7FA9895E967C6B FOREIGN KEY (run_id) REFERENCES ai_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN ai_suggestion.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_suggestion DROP CONSTRAINT FK_1C7FA9895E967C6B');
        $this->addSql('ALTER TABLE ai_suggestion DROP CONSTRAINT FK_1C7FA989432B3009');
        $this->addSql('ALTER TABLE ai_suggestion DROP CONSTRAINT FK_1C7FA989979B1AD6');
        $this->addSql('ALTER TABLE ai_run DROP CONSTRAINT FK_72BD07C6432B3009');
        $this->addSql('ALTER TABLE ai_run DROP CONSTRAINT FK_72BD07C6979B1AD6');
        $this->addSql('ALTER TABLE ai_agent DROP CONSTRAINT FK_2CBF7E87979B1AD6');

        $this->addSql('DROP TABLE ai_suggestion');
        $this->addSql('DROP TABLE ai_run');
        $this->addSql('DROP TABLE ai_agent');
    }
}
