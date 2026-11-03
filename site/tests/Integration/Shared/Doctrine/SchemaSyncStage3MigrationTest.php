<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Doctrine;

use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261103150000;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 4).'/migrations/Version20261103150000.php';

/**
 * Миграция этапа 3 docs/tasks/doctrine-schema-sync на состоянии «как на проде»: без ключа
 * marketplace_listings → products, с таблицами вне миграций и комментариями DC2Type.
 * БД после миграций такого состояния не имеет — оно строится сырым SQL в транзакции теста.
 */
final class SchemaSyncStage3MigrationTest extends IntegrationTestCase
{
    public function testRepairsProductionOnlyDrift(): void
    {
        $this->dropForeignKeyOn('marketplace_listings', 'product_id');
        $this->connection->executeStatement('CREATE TABLE bank_connection (id SERIAL PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE repair_backup_ad_load_jobs_20260527 (id UUID)');
        $this->connection->executeStatement("INSERT INTO repair_backup_ad_load_jobs_20260527 VALUES ('00000000-0000-0000-0000-000000000001')");
        $this->connection->executeStatement("COMMENT ON COLUMN marketplace_listings.created_at IS '(DC2Type:datetime_immutable)'");
        $this->connection->executeStatement('CREATE INDEX idx_month_close_lookup ON marketplace_month_closes (company_id, marketplace, year, month)');

        $this->migrateUp();

        self::assertTrue($this->hasForeignKeyOn('marketplace_listings', 'product_id'), 'ключ листинг → товар добавлен');
        self::assertTrue($this->hasForeignKeyOn('document_operations', 'project_direction_id'), 'ключ операции → проект на месте');
        self::assertNull($this->regclass('bank_connection'));
        self::assertNull($this->regclass('repair_backup_ad_load_jobs_20260527'));
        self::assertNull($this->regclass('idx_month_close_lookup'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM pg_description WHERE description LIKE '(DC2Type:%'"));
    }

    public function testRepeatedRunChangesNothing(): void
    {
        $this->migrateUp();

        self::assertTrue($this->hasForeignKeyOn('marketplace_sales', 'listing_id'));
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM pg_constraint WHERE conrelid = 'document_operations'::regclass AND contype = 'f' AND conname = 'fk_doc_ops_project_direction'",
        ), 'ключ не продублирован');
    }

    public function testAbortsWhenLegacyTableIsNotEmpty(): void
    {
        $this->connection->executeStatement('CREATE TABLE bank_import_cursor (id SERIAL PRIMARY KEY)');
        $this->connection->executeStatement('INSERT INTO bank_import_cursor DEFAULT VALUES');

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('bank_import_cursor не пуста');
        $this->migrateUp();
    }

    private function migrateUp(): void
    {
        $migration = new Version20261103150000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function dropForeignKeyOn(string $table, string $column): void
    {
        $name = $this->connection->fetchOne($this->foreignKeySql(), ['table' => $table, 'column' => $column]);
        self::assertIsString($name, sprintf('на БД после миграций ключ %s.%s есть', $table, $column));
        $this->connection->executeStatement(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $name));
    }

    private function hasForeignKeyOn(string $table, string $column): bool
    {
        return false !== $this->connection->fetchOne($this->foreignKeySql(), ['table' => $table, 'column' => $column]);
    }

    private function foreignKeySql(): string
    {
        return "SELECT conname FROM pg_constraint WHERE conrelid = to_regclass(:table) AND contype = 'f'
            AND conkey = ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = to_regclass(:table) AND attname = :column)]::smallint[]";
    }

    private function regclass(string $name): ?string
    {
        $value = $this->connection->fetchOne('SELECT to_regclass(:name)::text', ['name' => $name]);

        return \is_string($value) ? $value : null;
    }
}
