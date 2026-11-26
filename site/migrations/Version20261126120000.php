<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Принятые части пакетов 1С: метаданные, сырьё в объектном хранилище';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE onec_package (
                id UUID PRIMARY KEY,
                connection_id UUID NOT NULL REFERENCES onec_connection (id) ON DELETE CASCADE,
                package_id UUID NOT NULL,
                part INT NOT NULL,
                parts_total INT NOT NULL,
                kind VARCHAR(32) NOT NULL,
                period_from DATE NOT NULL,
                period_to DATE NOT NULL,
                include_open_outside_period BOOLEAN NOT NULL,
                processor_version VARCHAR(255) NOT NULL,
                versions JSONB NOT NULL,
                warnings JSONB NOT NULL,
                document_count INT NOT NULL,
                storage_path VARCHAR(255) NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                byte_size INT NOT NULL,
                status VARCHAR(16) NOT NULL,
                received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT uniq_onec_package_part UNIQUE (connection_id, package_id, part)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_onec_package_connection_received ON onec_package (connection_id, received_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE onec_package');
    }
}
