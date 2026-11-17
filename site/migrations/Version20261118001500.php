<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118001500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash: поиск фраз по истории подтверждённых операций компании.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cash_rule_phrase_search (
                task_id UUID NOT NULL PRIMARY KEY, company_id UUID NOT NULL, counterparty_id UUID NOT NULL,
                direction VARCHAR(10) NOT NULL, category_id UUID NOT NULL, request_key VARCHAR(64) NOT NULL, attempt INT NOT NULL,
                status VARCHAR(16) NOT NULL, phrase VARCHAR(255) DEFAULT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('ALTER TABLE cash_rule_phrase_search ADD CONSTRAINT fk_cash_rule_phrase_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX uniq_cash_rule_phrase_request ON cash_rule_phrase_search (company_id, request_key)');
        $this->addSql('CREATE INDEX idx_cash_rule_phrase_company ON cash_rule_phrase_search (company_id, requested_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cash_rule_phrase_search');
    }
}
