<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rebuild unit_economy_cost_mappings: replace code-based mapping with category_id-based, remove isSystem field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS unit_economy_cost_mappings');

        $this->addSql('
            CREATE TABLE unit_economy_cost_mappings (
                id                     UUID         NOT NULL,
                company_id             UUID         NOT NULL,
                marketplace            VARCHAR(50)  NOT NULL,
                cost_category_id       VARCHAR(36)  NOT NULL,
                cost_category_name     VARCHAR(255) NOT NULL,
                unit_economy_cost_type VARCHAR(50)  NOT NULL,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT uq_cost_mapping_company_marketplace_category
                    UNIQUE (company_id, marketplace, cost_category_id),
                CONSTRAINT chk_cost_mapping_marketplace
                    CHECK (marketplace IN (
                        \'wildberries\', \'ozon\', \'yandex_market\', \'sber_megamarket\'
                    )),
                CONSTRAINT chk_cost_mapping_unit_economy_cost_type
                    CHECK (unit_economy_cost_type IN (
                        \'logistics_to\', \'logistics_back\', \'storage\',
                        \'advertising_cpc\', \'advertising_other\', \'advertising_external\',
                        \'commission\', \'other\'
                    ))
            )
        ');

        $this->addSql('CREATE INDEX idx_cost_mapping_company ON unit_economy_cost_mappings (company_id)');
        $this->addSql('CREATE INDEX idx_cost_mapping_company_marketplace ON unit_economy_cost_mappings (company_id, marketplace)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS unit_economy_cost_mappings');
    }
}
