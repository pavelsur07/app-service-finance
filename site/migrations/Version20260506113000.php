<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial unique index for active Ozon sales_report raw documents by period with duplicate pre-check';
    }

    public function up(Schema $schema): void
    {
        $duplicatesCount = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT 1
                FROM marketplace_raw_documents
                WHERE marketplace = 'ozon'
                  AND document_type = 'sales_report'
                  AND (processing_status IS NULL OR processing_status <> 'failed')
                GROUP BY company_id, marketplace, document_type, period_from, period_to
                HAVING COUNT(*) > 1
            ) duplicate_groups
        SQL);

        $this->abortIf(
            $duplicatesCount > 0,
            'Cannot create uniq_marketplace_raw_documents_active_period: active duplicate raw documents exist. Run app:marketplace:ozon-raw-duplicates-audit and cleanup first.'
        );

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_marketplace_raw_documents_active_period
            ON marketplace_raw_documents (company_id, marketplace, document_type, period_from, period_to)
            WHERE marketplace = 'ozon'
              AND document_type = 'sales_report'
              AND (processing_status IS NULL OR processing_status <> 'failed')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_marketplace_raw_documents_active_period');
    }
}
