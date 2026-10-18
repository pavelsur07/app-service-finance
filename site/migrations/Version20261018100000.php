<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Витрина покрытия сервисов (docs/tasks/ozon-fetcher-coverage-admin, этап 2): снимок на
 * сервис и строки «ресурс × период». Только новые пустые таблицы, существующие данные не
 * трогаются. Обратимо: down() удаляет таблицы, витрину заново наполняет cron.
 */
final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Service gateway coverage read model: service_coverage_snapshot, service_coverage_row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_coverage_snapshot (id UUID NOT NULL, service VARCHAR(64) NOT NULL, generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, period_from DATE NOT NULL, period_to DATE NOT NULL, cabinets_total INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_service_coverage_snapshot_service ON service_coverage_snapshot (service)');
        $this->addSql('CREATE TABLE service_coverage_row (id UUID NOT NULL, service VARCHAR(64) NOT NULL, resource_type VARCHAR(128) NOT NULL, period_key VARCHAR(10) NOT NULL, cabinets_expected INT NOT NULL, done INT NOT NULL, running INT NOT NULL, pending INT NOT NULL, not_ready INT NOT NULL, failed_retryable INT NOT NULL, failed_permanent INT NOT NULL, last_success_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_service_coverage_row_key ON service_coverage_row (service, resource_type, period_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_coverage_row');
        $this->addSql('DROP TABLE service_coverage_snapshot');
    }
}
