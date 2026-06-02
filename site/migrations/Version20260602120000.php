<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce WB financial report business idempotency by company/marketplace/report day and exact-day raw document';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf(
            $platform !== 'postgresql',
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform),
        );

        $duplicateStatusGroups = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT 1
                FROM marketplace_financial_report_sync_statuses
                GROUP BY company_id, marketplace, report_type, business_date
                HAVING COUNT(*) > 1
            ) duplicate_groups
        SQL);

        $this->abortIf(
            $duplicateStatusGroups > 0,
            'Cannot create uniq_mfrss_company_marketplace_report_day: duplicate sync statuses exist for company/marketplace/report_type/business_date. Resolve them manually before migration.'
        );

        $duplicateRawGroups = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT 1
                FROM marketplace_raw_documents
                WHERE document_type = 'sales_report'
                  AND period_from = period_to
                  AND (processing_status IS NULL OR processing_status <> 'failed')
                GROUP BY company_id, marketplace, document_type, period_from, period_to
                HAVING COUNT(*) > 1
            ) duplicate_groups
        SQL);

        $this->abortIf(
            $duplicateRawGroups > 0,
            'Cannot create uniq_mrd_active_sales_report_exact_day: active exact-day sales_report raw duplicates exist for company/marketplace/date. Resolve them manually before migration.'
        );

        $this->addSql('ALTER TABLE marketplace_financial_report_sync_statuses DROP CONSTRAINT IF EXISTS uniq_mfrss_connection_report_day');
        $this->addSql('ALTER TABLE marketplace_financial_report_sync_statuses ADD CONSTRAINT uniq_mfrss_company_marketplace_report_day UNIQUE (company_id, marketplace, report_type, business_date)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mfrss_company_marketplace_date ON marketplace_financial_report_sync_statuses (company_id, marketplace, business_date)');

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_mrd_active_sales_report_exact_day
            ON marketplace_raw_documents (company_id, marketplace, document_type, period_from, period_to)
            WHERE document_type = 'sales_report'
              AND period_from = period_to
              AND (processing_status IS NULL OR processing_status <> 'failed')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf(
            $platform !== 'postgresql',
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform),
        );

        $this->addSql('DROP INDEX IF EXISTS uniq_mrd_active_sales_report_exact_day');
        $this->addSql('DROP INDEX IF EXISTS idx_mfrss_company_marketplace_date');
        $this->addSql('ALTER TABLE marketplace_financial_report_sync_statuses DROP CONSTRAINT IF EXISTS uniq_mfrss_company_marketplace_report_day');
        $this->addSql('ALTER TABLE marketplace_financial_report_sync_statuses ADD CONSTRAINT uniq_mfrss_connection_report_day UNIQUE (connection_id, report_type, business_date)');
    }
}
