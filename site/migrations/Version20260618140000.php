<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create P&L dirty period table and add rebuild audit columns';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql("CREATE TABLE pnl_dirty_periods (id UUID NOT NULL, company_id UUID NOT NULL, period_year SMALLINT NOT NULL, period_month SMALLINT NOT NULL, shop_ref VARCHAR(255) DEFAULT '' NOT NULL, status VARCHAR(32) DEFAULT 'pending' NOT NULL, reason VARCHAR(32) NOT NULL, marked_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, rebuilt_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, attempts INT DEFAULT 0 NOT NULL, last_error TEXT DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_pdp_key ON pnl_dirty_periods (company_id, period_year, period_month, shop_ref)');
        $this->addSql('CREATE INDEX idx_pdp_status_marked ON pnl_dirty_periods (status, marked_at)');
        $this->addSql('CREATE INDEX idx_pdp_company_status ON pnl_dirty_periods (company_id, status)');

        $this->addSql('ALTER TABLE pl_daily_totals ADD rebuilt_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pl_monthly_snapshots ADD rebuilt_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('ALTER TABLE pl_monthly_snapshots DROP COLUMN rebuilt_at');
        $this->addSql('ALTER TABLE pl_daily_totals DROP COLUMN rebuilt_at');
        $this->addSql('DROP TABLE pnl_dirty_periods');
    }
}
