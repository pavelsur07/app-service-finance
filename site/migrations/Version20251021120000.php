<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add operation_type column to cashflow categories for payment plan direction';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "cashflow_categories" ADD operation_type VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "cashflow_categories" DROP COLUMN operation_type');
    }
}
