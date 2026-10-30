<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cash: ИИ-подсказки статей ДДС (docs/tasks/ai/stages/stage-2.md, этап 2.1).
 *
 * Новая пустая таблица, существующие данные не трогаются; down() удаляет её.
 * Не больше одной открытой подсказки на операцию — частичный unique по статусам
 * requested/suggested. Операция удаляется — её подсказки тоже (каскад);
 * статья — ссылкой без FK: удаление статьи подсказка не блокирует, её проверяет «Принять».
 */
final class Version20261030180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash: cash_category_suggestion and cash_categorization_task for AI category suggestions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_category_suggestion (id UUID NOT NULL, company_id UUID NOT NULL, transaction_id UUID NOT NULL, task_id UUID NOT NULL, status VARCHAR(16) NOT NULL, category_id UUID DEFAULT NULL, confidence NUMERIC(5, 4) DEFAULT NULL, verdict VARCHAR(16) DEFAULT NULL, model VARCHAR(128) DEFAULT NULL, prompt_version VARCHAR(64) DEFAULT NULL, error_code VARCHAR(64) DEFAULT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, responded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decided_by_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_cash_category_suggestion_task_tx ON cash_category_suggestion (task_id, transaction_id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_cash_category_suggestion_open ON cash_category_suggestion (transaction_id) WHERE status IN ('requested', 'suggested')");
        $this->addSql('CREATE INDEX idx_cash_category_suggestion_company_tx ON cash_category_suggestion (company_id, transaction_id)');
        $this->addSql('CREATE INDEX idx_cash_category_suggestion_company_status ON cash_category_suggestion (company_id, status, requested_at)');
        $this->addSql('ALTER TABLE cash_category_suggestion ADD CONSTRAINT fk_cash_category_suggestion_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cash_category_suggestion ADD CONSTRAINT fk_cash_category_suggestion_transaction FOREIGN KEY (transaction_id) REFERENCES cash_transaction (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE cash_categorization_task (id UUID NOT NULL, company_id UUID NOT NULL, request_key VARCHAR(200) NOT NULL, payload JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, dispatched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, dispatch_count INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_cash_categorization_task_company_dispatched ON cash_categorization_task (company_id, dispatched_at)');
        $this->addSql('ALTER TABLE cash_categorization_task ADD CONSTRAINT fk_cash_categorization_task_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cash_categorization_task');
        $this->addSql('DROP TABLE cash_category_suggestion');
    }
}
