<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Отображаемое название контрагента — производное поле рядом с сырым name
 * (docs/plan/counterparty-display-name.md, этап 2): display_name, источник (auto / manual) и
 * версия правил. Только новые nullable-колонки: данные не меняются, name не трогается,
 * блокировка — мгновенный ALTER без перезаписи таблицы (Postgres 11+, без DEFAULT).
 * Обратимо: down() удаляет колонки; до первого пересчёта в них только NULL.
 */
final class Version20261014100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Counterparty display name fields: display_name, display_name_source, display_rules_version';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "counterparty" ADD display_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "counterparty" ADD display_name_source VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE "counterparty" ADD display_rules_version SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "counterparty" DROP display_name');
        $this->addSql('ALTER TABLE "counterparty" DROP display_name_source');
        $this->addSql('ALTER TABLE "counterparty" DROP display_rules_version');
    }
}
