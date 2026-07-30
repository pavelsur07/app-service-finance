<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correct historical Wildberries deductions whose source sign was lost when
 * marketplace_costs.amount was normalized to ABS().
 */
final class Version20260730120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark historical negative Wildberries deductions as STORNO using their raw report rows';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql(<<<'SQL'
            WITH negative_deductions AS (
                SELECT DISTINCT
                    raw_document.id AS raw_document_id,
                    raw_document.company_id,
                    BTRIM(COALESCE(raw_row ->> 'rrdId', raw_row ->> 'rrd_id')) AS rrd_id
                FROM marketplace_raw_documents raw_document
                CROSS JOIN LATERAL json_array_elements(
                    CASE
                        WHEN json_typeof(raw_document.raw_data) = 'array'
                            THEN raw_document.raw_data
                        ELSE '[]'::json
                    END
                ) raw_row
                WHERE raw_document.marketplace = 'wildberries'
                  AND BTRIM(COALESCE(raw_row ->> 'sellerOperName', raw_row ->> 'supplier_oper_name', ''))
                      = 'Удержание'
                  AND CASE
                      WHEN BTRIM(COALESCE(raw_row ->> 'deduction', ''))
                          ~ '^-?[0-9]+([.][0-9]+)?([eE][+-]?[0-9]+)?$'
                      THEN BTRIM(raw_row ->> 'deduction')::numeric
                      ELSE 0
                  END < 0
                  AND NULLIF(
                      BTRIM(COALESCE(raw_row ->> 'rrdId', raw_row ->> 'rrd_id', '')),
                      ''
                  ) IS NOT NULL
            )
            UPDATE marketplace_costs cost
            SET operation_type = 'storno'
            FROM negative_deductions deduction
            WHERE cost.marketplace = 'wildberries'
              AND cost.raw_document_id = deduction.raw_document_id
              AND cost.company_id = deduction.company_id
              AND split_part(cost.external_id, ':', 1) = 'wb'
              AND split_part(cost.external_id, ':', 2) = deduction.rrd_id
        SQL);

        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                skipped_documents integer;
            BEGIN
                SELECT COUNT(*) INTO skipped_documents
                FROM marketplace_raw_documents
                WHERE marketplace = 'wildberries'
                  AND json_typeof(raw_data) <> 'array';

                IF skipped_documents > 0 THEN
                    RAISE NOTICE
                        'WB negative deduction remediation skipped % raw document(s) with non-array raw_data',
                        skipped_documents;
                END IF;
            END $$;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'The original operation_type cannot be restored after correcting it from immutable WB raw data.',
        );
    }
}
