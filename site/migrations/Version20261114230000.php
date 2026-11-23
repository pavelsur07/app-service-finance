<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceEconomics (этап 6.5 marketplace-profit-pipeline): журнал отправок недельного отчёта.
 * Новая пустая таблица; откат удаляет только журнал — следующий прогон отправит неделю заново.
 */
final class Version20261114230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceEconomics: me_weekly_report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE me_weekly_report (company_id UUID NOT NULL, week_start DATE NOT NULL, delivery VARCHAR(16) NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, week_start, delivery))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE me_weekly_report');
    }
}
