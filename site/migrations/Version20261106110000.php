<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Лимит расходов ИИ и глубина выгрузки компании (docs/tasks/ai-observability-budget, этап 3).
 *
 * Две колонки со значением по умолчанию (500 ₽/мес, 90 дней — прежнее окно): существующие строки
 * (Вумджой) получают их без переписывания данных. Обратима.
 */
final class Version20261106110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ServiceGateway: ai_company_access.monthly_limit_rub, lookback_days';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_company_access ADD monthly_limit_rub NUMERIC(12, 2) DEFAULT 500 NOT NULL');
        $this->addSql('ALTER TABLE ai_company_access ADD lookback_days SMALLINT DEFAULT 90 NOT NULL');
        $this->addSql('ALTER TABLE ai_company_access ADD CONSTRAINT chk_ai_company_access_limit CHECK (monthly_limit_rub > 0)');
        $this->addSql('ALTER TABLE ai_company_access ADD CONSTRAINT chk_ai_company_access_lookback CHECK (lookback_days IN (7, 30, 90))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_company_access DROP monthly_limit_rub');
        $this->addSql('ALTER TABLE ai_company_access DROP lookback_days');
    }
}
