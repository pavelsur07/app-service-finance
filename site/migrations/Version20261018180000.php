<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Группы Ozon по типам начислений, выведенные из отчёта кабинета «Детализация
 * начислений». Таблица глобальная, заполняется командой
 * `app:ingestion:ozon-accrual:import-details-xlsx`; миграция данных не переносит.
 */
final class Version20261018180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ingestion Ozon accrual type groups from cabinet accrual details report';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql('CREATE TABLE ingest_ozon_accrual_type_groups (id UUID NOT NULL, type_id INT NOT NULL, group_code VARCHAR(64) DEFAULT NULL, status VARCHAR(16) NOT NULL, xlsx_type_name VARCHAR(255) NOT NULL, xlsx_group_label VARCHAR(255) NOT NULL, votes INT NOT NULL, rows_total INT NOT NULL, evidence_company_id UUID NOT NULL, period_from DATE NOT NULL, period_to DATE NOT NULL, disputed_group_code VARCHAR(64) DEFAULT NULL, disputed_votes INT DEFAULT NULL, disputed_company_id UUID DEFAULT NULL, disputed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_ozon_accrual_type_group_type_id ON ingest_ozon_accrual_type_groups (type_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql('DROP TABLE ingest_ozon_accrual_type_groups');
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
