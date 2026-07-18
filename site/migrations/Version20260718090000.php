<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718090000 extends AbstractMigration
{
    private const NULL_RESPONSIBILITY_CENTER_KEY = '00000000-0000-0000-0000-000000000000';

    public function getDescription(): string
    {
        return 'Switch P&L daily totals uniqueness to Project x responsibility center';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('LOCK TABLE pl_daily_totals IN SHARE ROW EXCLUSIVE MODE');

        $this->addSql(sprintf(
            <<<'SQL'
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pl_daily_totals
        WHERE pl_category_id IS NOT NULL
        GROUP BY company_id,
                 pl_category_id,
                 date,
                 project_direction_id,
                 COALESCE(responsibility_center_id, '%s'::uuid)
        HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Cannot add Project x CFO uniqueness: duplicate categorized pl_daily_totals rows exist';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM pl_daily_totals
        WHERE pl_category_id IS NULL
        GROUP BY company_id,
                 date,
                 project_direction_id,
                 COALESCE(responsibility_center_id, '%s'::uuid)
        HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Cannot add Project x CFO uniqueness: duplicate uncategorized pl_daily_totals rows exist';
    END IF;
END $$
SQL,
            self::NULL_RESPONSIBILITY_CENTER_KEY,
            self::NULL_RESPONSIBILITY_CENTER_KEY,
        ));

        $this->addSql('ALTER TABLE pl_daily_totals DROP CONSTRAINT IF EXISTS uniq_pl_daily_company_cat_date');
        $this->addSql('DROP INDEX IF EXISTS uniq_pl_daily_company_cat_date');
        $this->addSql('DROP INDEX IF EXISTS idx_pl_daily_company_cat_date');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pl_daily_company_cat_date_center ON pl_daily_totals (company_id, pl_category_id, date, project_direction_id, responsibility_center_id)');
        $this->addSql(sprintf(
            "CREATE UNIQUE INDEX uniq_pl_daily_company_cat_date_project_center ON pl_daily_totals (company_id, pl_category_id, date, project_direction_id, COALESCE(responsibility_center_id, '%s'::uuid)) WHERE pl_category_id IS NOT NULL",
            self::NULL_RESPONSIBILITY_CENTER_KEY,
        ));
        $this->addSql(sprintf(
            "CREATE UNIQUE INDEX uniq_pl_daily_uncat_date_project_center ON pl_daily_totals (company_id, date, project_direction_id, COALESCE(responsibility_center_id, '%s'::uuid)) WHERE pl_category_id IS NULL",
            self::NULL_RESPONSIBILITY_CENTER_KEY,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'P&L daily totals may contain separate responsibility-center buckets; restoring the old project-only unique key can lose data.',
        );
    }
}
