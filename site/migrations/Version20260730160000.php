<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contract-фаза нормализации названий контрагентов: name_core становится обязательным.
 *
 * Выполняется только после backfill (docs/tasks/counterparty-name-normalization).
 * На PROD backfill выполнен 30.07.2026: 317 строк, остаток name_core IS NULL = 0.
 * Миграция прерывается, если находит непересчитанные строки, — иначе она упала бы
 * на SET NOT NULL с невнятной ошибкой драйвера.
 */
final class Version20260730160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make counterparty.name_core NOT NULL after the normalization backfill';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $pending = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM "counterparty" WHERE name_core IS NULL OR name_core = \'\'',
        );

        $this->abortIf(
            $pending > 0,
            sprintf(
                'Найдено %d контрагентов без name_core. Сначала выполните app:counterparty:backfill-names, затем повторите миграцию.',
                $pending,
            ),
        );

        $this->addSql('ALTER TABLE "counterparty" ALTER COLUMN name_core SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('ALTER TABLE "counterparty" ALTER COLUMN name_core DROP NOT NULL');
    }
}
