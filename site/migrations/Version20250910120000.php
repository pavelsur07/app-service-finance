<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scope column and indexes to auto category tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE auto_category_template ADD scope VARCHAR(255) NOT NULL DEFAULT 'cashflow'");
        $this->addSql("CREATE INDEX IDX_AC_TEMPLATE_COMPANY_SCOPE_ACTIVE ON auto_category_template (company_id, scope, is_active)");
        $this->addSql("CREATE INDEX IDX_AC_TEMPLATE_PRIORITY ON auto_category_template (priority)");
        $this->addSql('CREATE INDEX IDX_AC_CONDITION_TEMPLATE_POSITION ON auto_category_condition (template_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_AC_TEMPLATE_COMPANY_SCOPE_ACTIVE');
        $this->addSql('DROP INDEX IDX_AC_TEMPLATE_PRIORITY');
        $this->addSql('DROP INDEX IDX_AC_CONDITION_TEMPLATE_POSITION');
        $this->addSql('ALTER TABLE auto_category_template DROP COLUMN scope');
    }
}
