<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash: фактическое начало учёта конфликтов компании и микросекунды для порядка событий audit_log.';
    }

    public function up(Schema $schema): void
    {
        // На PostgreSQL 15 повышение typmod сохраняет relfilenode таблицы и индексов;
        // если блокировку держит длинная транзакция, прекращаем ожидание.
        $this->addSql("SET LOCAL lock_timeout = '3s'");
        $this->addSql('ALTER TABLE audit_log ALTER COLUMN created_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
        $this->addSql('CREATE TABLE cash_auto_rule_observation (company_id UUID NOT NULL PRIMARY KEY, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Понижение точности аудита потеряет порядок уже записанных событий.');
    }
}
