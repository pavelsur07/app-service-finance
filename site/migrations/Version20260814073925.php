<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814073925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Balance: remove company_id foreign keys, keep scalar companyId columns';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('balance_categories')) {
            $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_constraint WHERE lower(conname) = lower('FK_BALANCE_CATEGORIES_COMPANY')) THEN ALTER TABLE balance_categories DROP CONSTRAINT FK_BALANCE_CATEGORIES_COMPANY; END IF; END $$;");
        }

        if ($schema->hasTable('balance_category_links')) {
            $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_constraint WHERE lower(conname) = lower('FK_BALANCE_LINK_COMPANY')) THEN ALTER TABLE balance_category_links DROP CONSTRAINT FK_BALANCE_LINK_COMPANY; END IF; END $$;");
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('balance_categories')) {
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE lower(conname) = lower('FK_BALANCE_CATEGORIES_COMPANY')) THEN ALTER TABLE balance_categories ADD CONSTRAINT FK_BALANCE_CATEGORIES_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
        }

        if ($schema->hasTable('balance_category_links')) {
            $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE lower(conname) = lower('FK_BALANCE_LINK_COMPANY')) THEN ALTER TABLE balance_category_links ADD CONSTRAINT FK_BALANCE_LINK_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
        }
    }
}
