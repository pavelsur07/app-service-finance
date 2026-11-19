<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove four obsolete entries from the Doctrine migration registry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                obsolete_versions CONSTANT text[] := ARRAY[
                    E'DoctrineMigrations\\Version20260114130328',
                    E'DoctrineMigrations\\Version20260201090000',
                    E'DoctrineMigrations\\Version20260405000001',
                    E'DoctrineMigrations\\Version20260405000002'
                ];
                required_version text;
                legacy_table text;
            BEGIN
                LOCK TABLE doctrine_migration_versions IN SHARE ROW EXCLUSIVE MODE;
                IF NOT EXISTS (SELECT 1 FROM doctrine_migration_versions WHERE version = ANY(obsolete_versions)) THEN
                    RETURN;
                END IF;

                FOREACH required_version IN ARRAY ARRAY[
                    E'DoctrineMigrations\\Version20260101090000',
                    E'DoctrineMigrations\\Version20261103150000'
                ] LOOP
                    IF NOT EXISTS (SELECT 1 FROM doctrine_migration_versions WHERE version = required_version) THEN
                        RAISE EXCEPTION 'Doctrine registry cleanup: required migration % is missing', required_version;
                    END IF;
                END LOOP;

                FOREACH legacy_table IN ARRAY ARRAY[
                    'bank_connection',
                    'bank_import_cursor',
                    'marketplace_raw_processing_step_runs',
                    'balance_categories',
                    'balance_category_links'
                ] LOOP
                    IF to_regclass(legacy_table) IS NOT NULL THEN
                        RAISE EXCEPTION 'Doctrine registry cleanup: legacy relation % still exists', legacy_table;
                    END IF;
                END LOOP;

                DELETE FROM doctrine_migration_versions WHERE version = ANY(obsolete_versions);
            END $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore the exact registry rows from the verified pre-release export.');
    }
}
