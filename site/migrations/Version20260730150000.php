<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Expand-фаза нормализации названий контрагентов: производные поля разбора
 * названия и КПП. Ничего не удаляется и не переименовывается.
 *
 * name_core остаётся nullable до backfill (docs/tasks/counterparty-name-normalization);
 * SET NOT NULL — отдельной contract-миграцией.
 */
final class Version20260730150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add normalized name fields (legal_form_hint, name_core) and kpp to counterparty';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        // similarity() нужна поиску с опечаткой и отчёту по кандидатам-дублям.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->addSql('ALTER TABLE "counterparty" ADD COLUMN legal_form_hint VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE "counterparty" ADD COLUMN name_core VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "counterparty" ADD COLUMN kpp VARCHAR(9) DEFAULT NULL');

        $this->addSql('CREATE INDEX idx_counterparty_company_name_core ON "counterparty" (company_id, name_core)');

        // updated_at переведён на datetime_immutable: тип колонки тот же, Doctrine
        // отличает immutable по комментарию DC2Type.
        $this->addSql('COMMENT ON COLUMN "counterparty".updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('COMMENT ON COLUMN "counterparty".updated_at IS NULL');
        $this->addSql('DROP INDEX IF EXISTS idx_counterparty_company_name_core');
        $this->addSql('ALTER TABLE "counterparty" DROP COLUMN kpp');
        $this->addSql('ALTER TABLE "counterparty" DROP COLUMN name_core');
        $this->addSql('ALTER TABLE "counterparty" DROP COLUMN legal_form_hint');
        // Расширение pg_trgm не удаляем: им могут пользоваться другие объекты БД.
    }
}
