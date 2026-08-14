<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814083741 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Balance: align enum columns and restore category foreign key';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('balance_categories')) {
            $this->addSql('ALTER TABLE balance_categories ALTER type TYPE VARCHAR(50)');
        }

        if ($schema->hasTable('balance_category_links')) {
            $this->addSql('ALTER TABLE balance_category_links ALTER source_type TYPE VARCHAR(50)');

            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE lower(conname) = lower('FK_BALANCE_LINK_CATEGORY')) THEN ALTER TABLE balance_category_links ADD CONSTRAINT FK_BALANCE_LINK_CATEGORY FOREIGN KEY (category_id) REFERENCES balance_categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = 'IDX_D79DC3BA12469DE2') THEN CREATE INDEX IDX_D79DC3BA12469DE2 ON balance_category_links (category_id); END IF; END $$;");
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('balance_category_links')) {
            $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_constraint WHERE lower(conname) = lower('FK_BALANCE_LINK_CATEGORY')) THEN ALTER TABLE balance_category_links DROP CONSTRAINT FK_BALANCE_LINK_CATEGORY; END IF; END $$;");
            $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_class WHERE relname = 'IDX_D79DC3BA12469DE2') THEN DROP INDEX IDX_D79DC3BA12469DE2; END IF; END $$;");
            $this->addSql('ALTER TABLE balance_category_links ALTER source_type TYPE VARCHAR(255)');
        }

        if ($schema->hasTable('balance_categories')) {
            $this->addSql('ALTER TABLE balance_categories ALTER type TYPE VARCHAR(255)');
        }
    }
}
