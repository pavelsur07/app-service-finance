<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260403000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UnitEconomyCostType: add acquiring, penalties, acceptance to CHECK constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                DROP CONSTRAINT IF EXISTS chk_cost_mapping_unit_economy_cost_type,
                ADD CONSTRAINT chk_cost_mapping_unit_economy_cost_type
                    CHECK (unit_economy_cost_type IN (
                        'logistics_to','logistics_back','storage',
                        'advertising_cpc','advertising_other','advertising_external',
                        'commission','acquiring','penalties','acceptance','other'
                    ))
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                DROP CONSTRAINT IF EXISTS chk_cost_mapping_unit_economy_cost_type,
                ADD CONSTRAINT chk_cost_mapping_unit_economy_cost_type
                    CHECK (unit_economy_cost_type IN (
                        'logistics_to','logistics_back','storage',
                        'advertising_cpc','advertising_other','advertising_external',
                        'commission','other'
                    ))
        SQL);
    }
}
