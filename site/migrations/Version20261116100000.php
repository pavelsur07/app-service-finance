<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplacePlProjection (этап 1 marketplace-pl-projection): правила компании «операция канона →
 * статья ОПиУ». Новая пустая таблица — откат удаляет её без потери данных.
 */
final class Version20261116100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplacePlProjection: mpp_rule';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mpp_rule (id UUID NOT NULL, company_id UUID NOT NULL, kind VARCHAR(64) DEFAULT NULL, channel VARCHAR(32) DEFAULT NULL, source_type VARCHAR(128) DEFAULT NULL, valid_from DATE NOT NULL, pl_category_id UUID DEFAULT NULL, origin VARCHAR(16) NOT NULL, template_version INT DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_by UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mpp_rule_key ON mpp_rule (company_id, kind, channel, source_type, valid_from) NULLS NOT DISTINCT');
        $this->addSql('CREATE INDEX idx_mpp_rule_company_category ON mpp_rule (company_id, pl_category_id)');
        $this->addSql('ALTER TABLE mpp_rule ADD CONSTRAINT chk_mpp_rule_key CHECK ((source_type IS NULL AND kind IS NOT NULL) OR (source_type IS NOT NULL AND channel IS NOT NULL AND kind IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mpp_rule');
    }
}
