<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add flow_kind and is_system to cashflow_categories';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cashflow_categories ADD flow_kind VARCHAR(255) DEFAULT 'OPERATING' NOT NULL");
        $this->addSql('ALTER TABLE cashflow_categories ADD is_system BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cashflow_categories DROP flow_kind');
        $this->addSql('ALTER TABLE cashflow_categories DROP is_system');
    }
}
