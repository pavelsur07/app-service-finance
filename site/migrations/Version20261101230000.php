<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Billing, этап 2 (docs/tasks/billing): всем кабинетам без подписки — тариф «Друг»
 * (решение Владельца 01.11.2026). Дальше тариф правится в админке.
 *
 * Повтор безопасен (WHERE NOT EXISTS). Строки миграции помечены created_at = 2026-11-01 23:00:00:
 * down() удаляет только их и только без оплат.
 */
final class Version20261101230000 extends AbstractMigration
{
    private const MARKER = '2026-11-01 23:00:00';

    public function getDescription(): string
    {
        return 'Billing: assign perpetual "friend" tariff to every company without a subscription';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                INSERT INTO billing_company_subscriptions (id, company_id, tariff_id, paid_from, paid_until, created_at, updated_at)
                SELECT gen_random_uuid(), c.id, t.id, (now() AT TIME ZONE 'Europe/Moscow')::date, NULL, :marker, :marker
                FROM companies c
                JOIN billing_tariffs t ON t.code = 'friend'
                WHERE NOT EXISTS (SELECT 1 FROM billing_company_subscriptions s WHERE s.company_id = c.id)
                SQL,
            ['marker' => self::MARKER],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                DELETE FROM billing_company_subscriptions s
                USING billing_tariffs t
                WHERE t.id = s.tariff_id
                  AND t.code = 'friend'
                  AND s.created_at = :marker
                  AND NOT EXISTS (SELECT 1 FROM billing_payments p WHERE p.company_id = s.company_id)
                SQL,
            ['marker' => self::MARKER],
        );
    }
}
