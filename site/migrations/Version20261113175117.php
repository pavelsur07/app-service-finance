<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceOperations (этап 3 marketplace-profit-pipeline): канон операций и отметки
 * грязных окон. Только новые пустые таблицы — откат удаляет их без потери данных.
 */
final class Version20261113175117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceOperations: mo_operation, mo_dirty_window';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mo_dirty_window (company_id UUID NOT NULL, channel VARCHAR(32) NOT NULL, connection_ref VARCHAR(64) NOT NULL, operation_date DATE NOT NULL, marked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, channel, connection_ref, operation_date))');
        $this->addSql('CREATE TABLE mo_operation (id UUID NOT NULL, company_id UUID NOT NULL, channel VARCHAR(32) NOT NULL, connection_ref VARCHAR(64) NOT NULL, operation_date DATE NOT NULL, listing_id UUID DEFAULT NULL, source_type VARCHAR(128) NOT NULL, kind VARCHAR(64) NOT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, quantity INT DEFAULT NULL, source_refs JSONB NOT NULL, rules_version VARCHAR(32) NOT NULL, allocation_key VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_mo_operation_window ON mo_operation (company_id, channel, connection_ref, operation_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mo_dirty_window');
        $this->addSql('DROP TABLE mo_operation');
    }
}
