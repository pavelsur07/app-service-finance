<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Строка marketplace_pl — значение для отчёта: доход +, расход −, сторно — обратный знак той же
 * статьи (`docs/tasks/pl-marketplace-sign/PLAN.md`).
 *
 * 1. Строки этапа выручки (`stream = revenue`) хранили знак флага is_negative маппинга продаж
 *    (противоречивого) — приводятся к ±|amount| по природе статьи. Регистр брал модуль итога статьи,
 *    а строк разного знака в одной статье этапа выручки нет (проверено на проде 23.11), поэтому
 *    результат регистра по выручке не меняется. Затраты уже в знаке отчёта и не трогаются.
 * 2. Расход или доход статьи за день бывает отрицательным (сторно больше начислений) — CHECK `≥ 0`
 *    на pl_daily_totals снимается.
 *
 * Совместимо со старой версией приложения: она берёт модуль, новая — знак, на нормализованных
 * строках выручки результат одинаков. Необратимо по знаку строк (исходный знак — шум флага):
 * откат — из бэкапа деплоя.
 */
final class Version20261123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'marketplace_pl: revenue lines in report sign, allow negative P&L daily totals.';
    }

    public function up(Schema $schema): void
    {
        $updated = $this->connection->executeStatement(<<<'SQL'
            UPDATE document_operations o
            SET amount = CASE WHEN c.flow = 'INCOME' THEN ABS(o.amount) ELSE -ABS(o.amount) END
            FROM documents d, pl_categories c
            WHERE d.id = o.document_id
              AND c.id = o.category_id
              AND d.type = 'marketplace_pl'
              AND d.stream = 'revenue'
              AND c.flow IN ('INCOME', 'EXPENSE')
              AND o.amount <> CASE WHEN c.flow = 'INCOME' THEN ABS(o.amount) ELSE -ABS(o.amount) END
            SQL);
        $this->write(sprintf('marketplace_pl revenue lines normalized to report sign: %d', $updated));

        $this->addSql('ALTER TABLE pl_daily_totals DROP CONSTRAINT IF EXISTS chk_pl_daily_totals_amounts');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Знак строк выручки marketplace_pl не восстанавливается: откат — из бэкапа деплоя.');
    }
}
