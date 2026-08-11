<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Освобождает ссылку на шаблон роли у завершённых приглашений.
 *
 * Version20260811150000 перевела `company_invites.role_id` на `ON DELETE RESTRICT`, поэтому
 * принятое, отозванное или просроченное приглашение навсегда запрещало бы удалить шаблон,
 * хотя применить его уже не может. Новые терминальные переходы очищают ссылку сами
 * (`CompanyInvite::accept()` / `revoke()`), просроченные освобождаются при удалении шаблона
 * (`DeleteCompanyRoleAction`), а эта миграция приводит в порядок существующие строки.
 *
 * Только очистка ссылки: сами приглашения, их статусы и участники не трогаются.
 * Down пустой намеренно — восстанавливать удалённую ссылку не из чего и незачем.
 */
final class Version20260811160000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return true;
    }

    public function getDescription(): string
    {
        return 'Clear company_invites.role_id for accepted, revoked and expired invites';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only.');

        $this->addSql(
            'UPDATE company_invites SET role_id = NULL WHERE role_id IS NOT NULL AND (accepted_at IS NOT NULL OR revoked_at IS NOT NULL OR expires_at <= CURRENT_TIMESTAMP)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only.');

        // Обратной операции нет: какой шаблон стоял у завершённого приглашения, уже не восстановить.
        // Down оставлен пустым осознанно, схема при этом не меняется.
    }
}
