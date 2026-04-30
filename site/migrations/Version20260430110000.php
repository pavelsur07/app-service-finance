<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marketplace initial sync: preflight duplicate audit + partial unique index for raw document idempotency';
    }

    public function up(Schema $schema): void
    {
        $failedStatus = 'failed';

        $duplicatesCount = (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM (
                    SELECT 1
                    FROM marketplace_raw_documents
                    WHERE processing_status IS NULL OR processing_status <> :failedStatus
                    GROUP BY
                        company_id,
                        marketplace,
                        document_type,
                        period_from,
                        period_to,
                        COALESCE(api_endpoint, '')
                    HAVING COUNT(*) > 1
                ) duplicate_groups
            SQL,
            ['failedStatus' => $failedStatus],
        );

        if ($duplicatesCount > 0) {
            $sampleDuplicateGroups = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT
                        company_id::text AS company_id,
                        marketplace,
                        document_type,
                        period_from::text AS period_from,
                        period_to::text AS period_to,
                        COALESCE(api_endpoint, '') AS api_endpoint,
                        COUNT(*) AS duplicates_count,
                        STRING_AGG(id::text, ', ' ORDER BY synced_at, id) AS duplicate_ids,
                        STRING_AGG(synced_at::text, ', ' ORDER BY synced_at, id) AS duplicate_synced_at
                    FROM marketplace_raw_documents
                    WHERE processing_status IS NULL OR processing_status <> :failedStatus
                    GROUP BY
                        company_id,
                        marketplace,
                        document_type,
                        period_from,
                        period_to,
                        COALESCE(api_endpoint, '')
                    HAVING COUNT(*) > 1
                    ORDER BY duplicates_count DESC, company_id, marketplace, document_type, period_from, period_to
                    LIMIT 10
                SQL,
                ['failedStatus' => $failedStatus],
            );

            $groupsPreview = array_map(
                static fn (array $group): string => sprintf(
                    '[company=%s marketplace=%s type=%s period=%s..%s endpoint=%s count=%s ids=%s synced_at=%s]',
                    $group['company_id'],
                    $group['marketplace'],
                    $group['document_type'],
                    $group['period_from'],
                    $group['period_to'],
                    $group['api_endpoint'],
                    $group['duplicates_count'],
                    $group['duplicate_ids'],
                    $group['duplicate_synced_at'],
                ),
                $sampleDuplicateGroups,
            );

            $this->abortIf(
                true,
                sprintf(
                    'Found %d duplicate non-failed initial-sync groups in marketplace_raw_documents. Resolve manually before migration. Sample groups: %s',
                    $duplicatesCount,
                    implode(' || ', $groupsPreview),
                ),
            );
        }

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_mrd_init_sync_period
            ON marketplace_raw_documents (
                company_id,
                marketplace,
                document_type,
                period_from,
                period_to,
                COALESCE(api_endpoint, '')
            )
            WHERE processing_status IS NULL OR processing_status <> 'failed'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_mrd_init_sync_period');
    }
}
