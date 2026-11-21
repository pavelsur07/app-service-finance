<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MoySklad mapping auto-apply setting per connection and marketplace (opt-in, off by default).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_mapping_auto_apply (
                company_id UUID NOT NULL, connection_id UUID NOT NULL, marketplace VARCHAR(20) NOT NULL,
                enabled BOOLEAN NOT NULL DEFAULT FALSE, actor_user_id UUID NOT NULL,
                size_characteristic_id VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (company_id, connection_id, marketplace),
                CONSTRAINT fk_ms_auto_apply_connection FOREIGN KEY (company_id, connection_id) REFERENCES moysklad_connections (company_id, id) ON DELETE RESTRICT,
                CONSTRAINT chk_ms_auto_apply_marketplace CHECK (marketplace IN ('ozon', 'wildberries'))
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moysklad_mapping_auto_apply');
    }
}
