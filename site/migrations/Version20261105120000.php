<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Подключение компаний к ИИ из админки (docs/tasks/ai-company-access, этап 1).
 *
 * Новая таблица вместо env `AI_SERVICE_COMPANY_ALLOWLIST`. Перенос единственной компании списка
 * (Вумджой) — чтобы ИИ у неё не прервался; если компании нет (dev/test), строка не создаётся.
 * Повтор безопасен (ON CONFLICT). Существующие данные не меняются; обратима (down удаляет таблицу).
 */
final class Version20261105120000 extends AbstractMigration
{
    private const VUMJOY = '19621cff-b028-45d9-9193-11f47ad9a8b2';

    public function getDescription(): string
    {
        return 'ServiceGateway: ai_company_access (подключение компаний к ИИ)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_company_access (id UUID NOT NULL, company_id UUID NOT NULL, enabled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, enabled_by_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_ai_company_access_company ON ai_company_access (company_id)');
        $this->addSql(
            "INSERT INTO ai_company_access (id, company_id, enabled_at, enabled_by_user_id)
             SELECT '019a5390-0000-7000-8000-000000000001', id, now() AT TIME ZONE 'Europe/Moscow', NULL
             FROM companies WHERE id = :company
             ON CONFLICT (company_id) DO NOTHING",
            ['company' => self::VUMJOY],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_company_access');
    }
}
