<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UnitEconomyCostMapping contract: drop old columns and unused index after deploy verification';
    }

    public function up(Schema $schema): void
    {
        // Удаляем временный индекс (заменён UNIQUE constraint из миграции 2)
        $this->addSql('DROP INDEX IF EXISTS idx_cost_mapping_category_id');

        // Удаляем старые колонки — новый код уже не читает их
        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                DROP COLUMN IF EXISTS is_system,
                DROP COLUMN IF EXISTS cost_category_code
        SQL);

        // Финальные индексы — убедиться что существуют
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_cost_mapping_company
                ON unit_economy_cost_mappings (company_id)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_cost_mapping_company_marketplace
                ON unit_economy_cost_mappings (company_id, marketplace)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_cost_mapping_company');
        $this->addSql('DROP INDEX IF EXISTS idx_cost_mapping_company_marketplace');

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                ADD COLUMN IF NOT EXISTS is_system          BOOLEAN     NOT NULL DEFAULT FALSE,
                ADD COLUMN IF NOT EXISTS cost_category_code VARCHAR(50) DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_cost_mapping_category_id
                ON unit_economy_cost_mappings (company_id, marketplace, cost_category_id)
        SQL);
    }
}
