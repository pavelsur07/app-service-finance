<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица для логирования неизвестных затрат маркетплейса.
 * Когда service_name не найден в OzonServiceCategoryMap — пишем сюда.
 */
final class Version20260330000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_mapping_errors table for unknown cost tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_mapping_errors (
                id              UUID        NOT NULL,
                company_id      UUID        NOT NULL,
                marketplace     VARCHAR(50) NOT NULL,
                year            SMALLINT    NOT NULL,
                month           SMALLINT    NOT NULL,
                service_name    TEXT        NOT NULL,
                operation_type  TEXT        NOT NULL DEFAULT '',
                total_amount    NUMERIC(14,2) NOT NULL DEFAULT 0,
                rows_count      INT         NOT NULL DEFAULT 1,
                sample_raw_json JSONB       DEFAULT NULL,
                detected_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                resolved_at     TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_mapping_errors_company ON marketplace_mapping_errors (company_id)');
        $this->addSql('CREATE INDEX idx_mapping_errors_unresolved ON marketplace_mapping_errors (resolved_at) WHERE resolved_at IS NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_mapping_error ON marketplace_mapping_errors (company_id, marketplace, year, month, service_name)');

        $this->addSql('COMMENT ON COLUMN marketplace_mapping_errors.detected_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN marketplace_mapping_errors.resolved_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_mapping_errors');
    }
}
