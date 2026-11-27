<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MoySklad stores: AI suggestions of a Balance account for stores the name rules do not cover (moysklad_store_account_suggest).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_store_account_suggestions (
                company_id UUID NOT NULL, connection_id UUID NOT NULL, store_external_id UUID NOT NULL, ai_task_id UUID NOT NULL,
                store_fingerprint VARCHAR(64) NOT NULL, prompt_version VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL,
                account_code VARCHAR(64) DEFAULT NULL, excluded BOOLEAN DEFAULT false NOT NULL, confident BOOLEAN DEFAULT false NOT NULL,
                reason VARCHAR(500) DEFAULT NULL,
                requested_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, completed_at TIMESTAMP(3) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (company_id, connection_id, store_external_id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_ms_store_account_suggestions_task ON moysklad_store_account_suggestions (company_id, ai_task_id)');
        $this->addSql('ALTER TABLE moysklad_store_account_suggestions ADD CONSTRAINT fk_ms_store_account_suggestions_store FOREIGN KEY (company_id, connection_id, store_external_id) REFERENCES moysklad_stores (company_id, connection_id, external_id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moysklad_store_account_suggestions');
    }
}
