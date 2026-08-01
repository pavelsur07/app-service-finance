<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Отчёт /inventory/stocks ищет ближайший день со снимком по (company_id, source, snapshot_date <= :date).
 * Существующие индексы либо не содержат source, либо упорядочены по snapshot_at, из-за чего поиск
 * для источника без синхронизации может пройти по всей истории компании.
 */
final class Version20260801120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add (company_id, source, snapshot_date) index on inventory_stock_snapshots for the stock report date lookup';
    }

    /**
     * CREATE INDEX CONCURRENTLY нельзя выполнять внутри транзакции, а обычный CREATE INDEX
     * заблокировал бы записи upsertDaySnapshot() на время построения.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inventory_stock_company_source_date ON inventory_stock_snapshots (company_id, source, snapshot_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_inventory_stock_company_source_date');
    }
}
