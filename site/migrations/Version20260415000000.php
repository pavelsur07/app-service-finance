<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Добавляет тип подключения (connection_type) к marketplace_connections.
 *
 * Одна компания может иметь несколько подключений к одному маркетплейсу,
 * различающихся типом (например, Ozon Seller API и Ozon Performance API).
 * Уникальность теперь по (company_id, marketplace, connection_type) вместо
 * (company_id, marketplace).
 *
 * Существующие записи получают значение 'seller' через DEFAULT.
 */
final class Version20260415000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add connection_type to marketplace_connections and widen unique constraint to (company_id, marketplace, connection_type)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_connections')) {
            return;
        }

        $table = $schema->getTable('marketplace_connections');

        if (!$table->hasColumn('connection_type')) {
            $this->addSql(
                "ALTER TABLE marketplace_connections ADD COLUMN connection_type VARCHAR(20) NOT NULL DEFAULT 'seller'"
            );
        }

        $this->addSql('DROP INDEX IF EXISTS uniq_company_marketplace');

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_company_marketplace_type
            ON marketplace_connections (company_id, marketplace, connection_type)
        SQL);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_connections')) {
            return;
        }

        $this->addSql('DROP INDEX IF EXISTS uniq_company_marketplace_type');

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_company_marketplace
            ON marketplace_connections (company_id, marketplace)
        SQL);

        $table = $schema->getTable('marketplace_connections');

        if ($table->hasColumn('connection_type')) {
            $this->addSql('ALTER TABLE marketplace_connections DROP COLUMN connection_type');
        }
    }
}
