<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add operation_type column and index to marketplace_costs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_costs ADD COLUMN operation_type VARCHAR(10) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_cost_operation_type ON marketplace_costs (operation_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_cost_operation_type');
        $this->addSql('ALTER TABLE marketplace_costs DROP COLUMN operation_type');
    }
}
