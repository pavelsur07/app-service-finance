<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Справочник типов начислений Ozon (`/v1/finance/accrual/types`) в Ingestion.
 *
 * Таблица общая для всех компаний: справочник у Ozon один на всех продавцов.
 * Заполняется нормализацией новой версии и командой
 * `app:ingestion:ozon-accrual:sync-types`; миграция данных не переносит.
 */
final class Version20261018140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ingestion Ozon accrual types dictionary table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql('CREATE TABLE ingest_ozon_accrual_types (id UUID NOT NULL, type_id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(500) DEFAULT NULL, first_seen_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, removed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, version_fetched_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, last_raw_record_id UUID NOT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_ozon_accrual_type_type_id ON ingest_ozon_accrual_types (type_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql('DROP TABLE ingest_ozon_accrual_types');
    }

    private function abortIfNotPostgres(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );
    }
}
