<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260717120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable responsibility centers to Cash and Finance facts';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $duplicateGroups = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT 1
                FROM pl_daily_totals
                GROUP BY company_id, pl_category_id, date, project_direction_id
                HAVING COUNT(*) > 1
            ) duplicates
            SQL);

        $this->abortIf(
            $duplicateGroups > 0,
            sprintf('Found %d duplicate P&L daily aggregation group(s); no data was changed.', $duplicateGroups),
        );

        $this->addSql('ALTER TABLE cash_transaction ADD responsibility_center_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_cash_transaction_responsibility_center ON cash_transaction (responsibility_center_id)');
        $this->addSql('ALTER TABLE cash_transaction ADD CONSTRAINT fk_cash_transaction_responsibility_center FOREIGN KEY (responsibility_center_id) REFERENCES financial_responsibility_centers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE documents ADD responsibility_center_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_documents_responsibility_center ON documents (responsibility_center_id)');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT fk_documents_responsibility_center FOREIGN KEY (responsibility_center_id) REFERENCES financial_responsibility_centers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE document_operations ADD responsibility_center_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_doc_ops_responsibility_center ON document_operations (responsibility_center_id)');
        $this->addSql('ALTER TABLE document_operations ADD CONSTRAINT fk_doc_ops_responsibility_center FOREIGN KEY (responsibility_center_id) REFERENCES financial_responsibility_centers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE pl_daily_totals ADD responsibility_center_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_pl_daily_responsibility_center ON pl_daily_totals (responsibility_center_id)');
        $this->addSql('ALTER TABLE pl_daily_totals ADD CONSTRAINT fk_pl_daily_responsibility_center FOREIGN KEY (responsibility_center_id) REFERENCES financial_responsibility_centers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE pl_daily_totals DROP CONSTRAINT uniq_pl_daily_company_cat_date');
        $this->addSql('ALTER TABLE pl_daily_totals ADD CONSTRAINT uniq_pl_daily_company_cat_date UNIQUE NULLS NOT DISTINCT (company_id, pl_category_id, date, project_direction_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pl_daily_company_cat_date_project_center ON pl_daily_totals (company_id, pl_category_id, date, project_direction_id, responsibility_center_id) NULLS NOT DISTINCT');

        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM cash_transaction WHERE responsibility_center_id IS NOT NULL)
                    OR EXISTS (SELECT 1 FROM documents WHERE responsibility_center_id IS NOT NULL)
                    OR EXISTS (SELECT 1 FROM document_operations WHERE responsibility_center_id IS NOT NULL)
                    OR EXISTS (SELECT 1 FROM pl_daily_totals WHERE responsibility_center_id IS NOT NULL)
                THEN
                    RAISE EXCEPTION 'Stage 7.5 must not classify existing financial facts';
                END IF;
            END $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Responsibility-center columns may contain financial classifications after later stages; use a reviewed forward migration or restore a backup.',
        );
    }
}
