<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251201090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allow_pl_document flag and pl_category relation to cashflow categories';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cashflow_categories ADD allow_pl_document BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE cashflow_categories ADD pl_category_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE cashflow_categories ADD CONSTRAINT FK_CASHFLOW_CATEGORIES_PL_CATEGORY FOREIGN KEY (pl_category_id) REFERENCES pl_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_CASHFLOW_CATEGORIES_PL_CATEGORY ON cashflow_categories (pl_category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_CASHFLOW_CATEGORIES_PL_CATEGORY');
        $this->addSql('ALTER TABLE cashflow_categories DROP CONSTRAINT IF EXISTS FK_CASHFLOW_CATEGORIES_PL_CATEGORY');
        $this->addSql('ALTER TABLE cashflow_categories DROP allow_pl_document');
        $this->addSql('ALTER TABLE cashflow_categories DROP pl_category_id');
    }
}
