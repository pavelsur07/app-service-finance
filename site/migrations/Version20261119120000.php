<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Источник переходов в ключе дня воронки: у поиска WB свои факты и своя ревизия дня. Существующие дни —
 * общая воронка (`total`). Строки `mfn_row` источник уже несут.
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceFunnel: mfn_day.source in primary key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mfn_day ADD source VARCHAR(16) DEFAULT 'total' NOT NULL");
        $this->addSql('ALTER TABLE mfn_day ALTER source DROP DEFAULT');
        $this->addSql('ALTER TABLE mfn_day DROP CONSTRAINT mfn_day_pkey');
        $this->addSql('ALTER TABLE mfn_day ADD PRIMARY KEY (company_id, channel, connection_ref, operation_date, source)');
    }

    public function down(Schema $schema): void
    {
        // Дни и строки поиска — производные от фактов: после повторного up перевод восстановит их сам.
        $this->addSql("DELETE FROM mfn_row WHERE source <> 'total'");
        $this->addSql("DELETE FROM mfn_day WHERE source <> 'total'");
        $this->addSql('ALTER TABLE mfn_day DROP CONSTRAINT mfn_day_pkey');
        $this->addSql('ALTER TABLE mfn_day DROP source');
        $this->addSql('ALTER TABLE mfn_day ADD PRIMARY KEY (company_id, channel, connection_ref, operation_date)');
    }
}
