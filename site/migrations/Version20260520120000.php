<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace financial report sync status and error tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_financial_report_sync_statuses (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                connection_id UUID NOT NULL,
                marketplace VARCHAR(16) NOT NULL,
                report_type VARCHAR(64) NOT NULL,
                api_endpoint VARCHAR(128) NOT NULL,
                business_date DATE NOT NULL,
                status VARCHAR(32) NOT NULL,
                mode VARCHAR(32) DEFAULT NULL,
                raw_document_id UUID DEFAULT NULL,
                records_count INT NOT NULL DEFAULT 0,
                rows_hash VARCHAR(128) DEFAULT NULL,
                attempts INT NOT NULL DEFAULT 0,
                last_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                next_retry_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_success_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_empty_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_error_class VARCHAR(255) DEFAULT NULL,
                last_error_message TEXT DEFAULT NULL,
                last_error_status_code INT DEFAULT NULL,
                last_error_response_excerpt TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT uniq_mfrss_connection_report_day UNIQUE (connection_id, report_type, business_date)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_mfrss_company_marketplace_date ON marketplace_financial_report_sync_statuses (company_id, marketplace, business_date)');
        $this->addSql('CREATE INDEX idx_mfrss_connection_status ON marketplace_financial_report_sync_statuses (connection_id, status)');
        $this->addSql('CREATE INDEX idx_mfrss_status_next_retry_at ON marketplace_financial_report_sync_statuses (status, next_retry_at)');
        $this->addSql('CREATE INDEX idx_mfrss_raw_document_id ON marketplace_financial_report_sync_statuses (raw_document_id)');

        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_statuses.business_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_statuses.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_statuses.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_statuses.last_attempt_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_statuses.next_retry_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_statuses.started_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_statuses.finished_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_statuses.last_success_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_statuses.last_empty_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_financial_report_sync_errors (
                id UUID NOT NULL,
                sync_status_id UUID NOT NULL,
                company_id UUID NOT NULL,
                connection_id UUID NOT NULL,
                business_date DATE NOT NULL,
                error_class VARCHAR(255) NOT NULL,
                error_message TEXT NOT NULL,
                status_code INT DEFAULT NULL,
                response_excerpt TEXT DEFAULT NULL,
                request_payload JSON DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_mfrse_status_created ON marketplace_financial_report_sync_errors (sync_status_id, created_at)');
        $this->addSql('CREATE INDEX idx_mfrse_company_date ON marketplace_financial_report_sync_errors (company_id, business_date)');
        $this->addSql('CREATE INDEX idx_mfrse_connection_date ON marketplace_financial_report_sync_errors (connection_id, business_date)');

        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_errors.business_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_financial_report_sync_errors.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_financial_report_sync_errors');
        $this->addSql('DROP TABLE marketplace_financial_report_sync_statuses');
    }
}
