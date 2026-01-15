<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add balance categories and links tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE balance_categories (id UUID NOT NULL, company_id UUID NOT NULL, name VARCHAR(255) NOT NULL, code VARCHAR(64) DEFAULT NULL, type VARCHAR(32) NOT NULL, parent_id UUID DEFAULT NULL, level INT NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, is_visible BOOLEAN NOT NULL DEFAULT true, PRIMARY KEY(id))");
        $this->addSql("CREATE INDEX idx_balance_cat_company ON balance_categories (company_id)");
        $this->addSql("CREATE INDEX idx_balance_cat_company_parent ON balance_categories (company_id, parent_id)");
        $this->addSql("CREATE UNIQUE INDEX uniq_balance_cat_company_code ON balance_categories (company_id, code)");
        $this->addSql("COMMENT ON COLUMN balance_categories.id IS '(DC2Type:guid)'");
        $this->addSql("COMMENT ON COLUMN balance_categories.company_id IS '(DC2Type:guid)'");
        $this->addSql("COMMENT ON COLUMN balance_categories.parent_id IS '(DC2Type:guid)'");
        $this->addSql("ALTER TABLE balance_categories ADD CONSTRAINT FK_BALANCE_CATEGORIES_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE");
        $this->addSql("ALTER TABLE balance_categories ADD CONSTRAINT FK_BALANCE_CATEGORIES_PARENT FOREIGN KEY (parent_id) REFERENCES balance_categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE");

        $this->addSql("CREATE TABLE balance_category_links (id UUID NOT NULL, company_id UUID NOT NULL, category_id UUID NOT NULL, source_type VARCHAR(64) NOT NULL, source_id UUID DEFAULT NULL, sign INT NOT NULL DEFAULT 1, position INT NOT NULL DEFAULT 0, PRIMARY KEY(id))");
        $this->addSql("CREATE INDEX idx_balance_link_company ON balance_category_links (company_id)");
        $this->addSql("CREATE INDEX idx_balance_link_company_category ON balance_category_links (company_id, category_id)");
        $this->addSql("CREATE UNIQUE INDEX uniq_balance_link ON balance_category_links (company_id, category_id, source_type, source_id)");
        $this->addSql("COMMENT ON COLUMN balance_category_links.id IS '(DC2Type:guid)'");
        $this->addSql("COMMENT ON COLUMN balance_category_links.company_id IS '(DC2Type:guid)'");
        $this->addSql("COMMENT ON COLUMN balance_category_links.category_id IS '(DC2Type:guid)'");
        $this->addSql("COMMENT ON COLUMN balance_category_links.source_id IS '(DC2Type:guid)'");
        $this->addSql("ALTER TABLE balance_category_links ADD CONSTRAINT FK_BALANCE_LINK_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE");
        $this->addSql("ALTER TABLE balance_category_links ADD CONSTRAINT FK_BALANCE_LINK_CATEGORY FOREIGN KEY (category_id) REFERENCES balance_categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE balance_category_links DROP CONSTRAINT FK_BALANCE_LINK_CATEGORY');
        $this->addSql('ALTER TABLE balance_category_links DROP CONSTRAINT FK_BALANCE_LINK_COMPANY');
        $this->addSql('ALTER TABLE balance_categories DROP CONSTRAINT FK_BALANCE_CATEGORIES_COMPANY');
        $this->addSql('ALTER TABLE balance_categories DROP CONSTRAINT FK_BALANCE_CATEGORIES_PARENT');
        $this->addSql('DROP TABLE balance_category_links');
        $this->addSql('DROP TABLE balance_categories');
    }
}
