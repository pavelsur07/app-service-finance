<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce active raw document idempotency by company/marketplace/type/endpoint/period with duplicate pre-check';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf(
            $platform !== 'postgresql',
            sprintf(
                'Migration %s supports only PostgreSQL; got platform "%s".',
                self::class,
                $platform,
            ),
        );

        $duplicatesCount = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT 1
                FROM marketplace_raw_documents
                WHERE processing_status IS NULL OR processing_status <> 'failed'
                GROUP BY company_id, marketplace, document_type, api_endpoint, period_from, period_to
                HAVING COUNT(*) > 1
            ) duplicate_groups
        SQL);

        $this->abortIf(
            $duplicatesCount > 0,
            'Cannot create uniq_mrd_active_company_marketplace_type_endpoint_period: active duplicates exist in marketplace_raw_documents. Run TASK-006 audit/cleanup first.'
        );

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_mrd_active_company_marketplace_type_endpoint_period
            ON marketplace_raw_documents (company_id, marketplace, document_type, api_endpoint, period_from, period_to)
            WHERE processing_status IS NULL OR processing_status <> 'failed'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf(
            $platform !== 'postgresql',
            sprintf(
                'Migration %s supports only PostgreSQL; got platform "%s".',
                self::class,
                $platform,
            ),
        );

        $this->addSql('DROP INDEX IF EXISTS uniq_mrd_active_company_marketplace_type_endpoint_period');
    }
}
