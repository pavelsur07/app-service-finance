<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Флаг автоприменения ИИ-подсказок статей ДДС по компании (docs/tasks/ai/stages/stage-2.md, 2.3).
 * Новая таблица, данные не трогает; нет строки — автоприменение выключено.
 */
final class Version20261030220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash: cash_ai_categorization_setting — AI auto-apply flag per company';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_ai_categorization_setting (company_id UUID NOT NULL, auto_apply BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id))');
        $this->addSql('ALTER TABLE cash_ai_categorization_setting ADD CONSTRAINT fk_cash_ai_categorization_setting_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cash_ai_categorization_setting');
    }
}
