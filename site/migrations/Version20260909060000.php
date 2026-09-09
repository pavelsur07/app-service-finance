<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Состояние аутентификации подключения к маркетплейсу.
 *
 * До этой миграции факт «маркетплейс перестал принимать ключ» нигде не жил.
 * Обработчик синхронизации помечал ПАДАЮЩЕЕ ЗАДАНИЕ причиной `auth` и уходил,
 * а само подключение оставалось внешне здоровым: `is_active = true`,
 * `last_sync_error` пустой. Из-за этого крон бесконечно ставил новые задания
 * по заведомо мёртвому ключу, каждое падало в failed-очередь, а в кабинете не
 * показывалось ничего — страница подключений выводит ошибку только при
 * `is_active = false`.
 *
 * Отдельные колонки, а не переиспользование `is_active` и `last_sync_error`:
 * активность выключает Владелец руками, и подмена её автоматикой отняла бы у
 * него возможность отличить «я сам отключил» от «ключ протух». Текст
 * `last_sync_error` перезаписывается любой другой ошибкой синхронизации, то
 * есть не годится как устойчивый признак состояния.
 *
 * Expand-only и обратимая: колонки добавляются со значениями по умолчанию,
 * обратного заполнения нет и не требуется. Все существующие строки получают
 * `ok` — это верное начальное состояние даже для подключения, ключ которого
 * уже мёртв: первые же три отказа переведут его в `failed` штатным путём, и
 * догадываться о прошлом по failed-очереди не нужно.
 */
final class Version20260909060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds connector authentication state to marketplace connections.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE marketplace_connections ADD auth_status VARCHAR(20) DEFAULT 'ok' NOT NULL");
        $this->addSql('ALTER TABLE marketplace_connections ADD auth_failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_connections ADD auth_failure_count INT DEFAULT 0 NOT NULL');
        $this->addSql("COMMENT ON COLUMN marketplace_connections.auth_failed_at IS '(DC2Type:datetime_immutable)'");

        // Своего индекса нет — намеренно.
        //
        // Первая редакция заводила частичный `... (company_id) WHERE
        // auth_status = 'failed'`, и он был неправ дважды. Реестр подключений —
        // строка на пару (компания, маркетплейс, тип), то есть таблица заведомо
        // мелкая, а единственный читатель фильтрует по `company_id`, который уже
        // покрыт `idx_connection_company`. Вдобавок частичный индекс не
        // выражается в маппинге Doctrine, поэтому `doctrine:schema:validate`
        // вечно предлагал бы его удалить, добавив ещё одну строку в и без того
        // расходящийся отчёт.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_connections DROP auth_failure_count');
        $this->addSql('ALTER TABLE marketplace_connections DROP auth_failed_at');
        $this->addSql('ALTER TABLE marketplace_connections DROP auth_status');
    }
}
