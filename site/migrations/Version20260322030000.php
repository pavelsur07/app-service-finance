<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align marketplace_connections schema with MarketplaceConnection entity: add client_id';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_connections')) {
            return;
        }

        $table = $schema->getTable('marketplace_connections');

        if (!$table->hasColumn('client_id')) {
            $this->addSql('ALTER TABLE marketplace_connections ADD client_id VARCHAR(100) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_connections')) {
            return;
        }

        $table = $schema->getTable('marketplace_connections');

        if ($table->hasColumn('client_id')) {
            $this->addSql('ALTER TABLE marketplace_connections DROP client_id');
        }
    }
}
