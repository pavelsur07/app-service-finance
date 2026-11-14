<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add global technical PaymentIntake quality monitor and daily read model; no financial data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_quality_monitor (id UUID NOT NULL, suite VARCHAR(64) NOT NULL, enabled_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, last_pull_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, configuration_fingerprint VARCHAR(64) DEFAULT NULL, remote_enabled BOOLEAN DEFAULT NULL, error_code VARCHAR(64) DEFAULT NULL, pull_revision INT DEFAULT 0 NOT NULL, alert_key VARCHAR(200) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_quality_monitor_suite ON ai_quality_monitor (suite)');
        $this->addSql('CREATE TABLE ai_quality_run (id UUID NOT NULL, suite VARCHAR(64) NOT NULL, run_date DATE NOT NULL, trace_id UUID NOT NULL, remote_id UUID DEFAULT NULL, status VARCHAR(32) NOT NULL, configuration_fingerprint VARCHAR(64) DEFAULT NULL, report JSONB DEFAULT NULL, quality_passed BOOLEAN NOT NULL, remote_updated_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_quality_run_day ON ai_quality_run (suite, run_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_quality_run');
        $this->addSql('DROP TABLE ai_quality_monitor');
    }
}
