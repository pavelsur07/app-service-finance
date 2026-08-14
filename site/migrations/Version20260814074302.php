<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814074302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Balance: add created_at/updated_at to categories and links';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('balance_categories')) {
            $this->addSql('ALTER TABLE balance_categories ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP');
            $this->addSql('ALTER TABLE balance_categories ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP');
            $this->addSql('COMMENT ON COLUMN balance_categories.created_at IS \'(DC2Type:datetime_immutable)\'');
            $this->addSql('COMMENT ON COLUMN balance_categories.updated_at IS \'(DC2Type:datetime_immutable)\'');
            $this->addSql('ALTER TABLE balance_categories ALTER created_at DROP DEFAULT');
            $this->addSql('ALTER TABLE balance_categories ALTER updated_at DROP DEFAULT');
        }

        if ($schema->hasTable('balance_category_links')) {
            $this->addSql('ALTER TABLE balance_category_links ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP');
            $this->addSql('ALTER TABLE balance_category_links ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP');
            $this->addSql('COMMENT ON COLUMN balance_category_links.created_at IS \'(DC2Type:datetime_immutable)\'');
            $this->addSql('COMMENT ON COLUMN balance_category_links.updated_at IS \'(DC2Type:datetime_immutable)\'');
            $this->addSql('ALTER TABLE balance_category_links ALTER created_at DROP DEFAULT');
            $this->addSql('ALTER TABLE balance_category_links ALTER updated_at DROP DEFAULT');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('balance_categories')) {
            $this->addSql('ALTER TABLE balance_categories DROP created_at');
            $this->addSql('ALTER TABLE balance_categories DROP updated_at');
        }

        if ($schema->hasTable('balance_category_links')) {
            $this->addSql('ALTER TABLE balance_category_links DROP created_at');
            $this->addSql('ALTER TABLE balance_category_links DROP updated_at');
        }
    }
}
