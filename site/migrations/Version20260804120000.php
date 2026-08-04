<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Восстанавливает uniq_plcat_company_code (company_id, code) на pl_categories.
 *
 * Индекс существовал с Version20251001120000, но был удалён
 * Version20251105174115 (auto-generated migrations:diff) — PLCategory
 * объявлял уникальность только через Symfony #[UniqueEntity], без парного
 * Doctrine ORM #[ORM\UniqueConstraint], поэтому diff-инструмент не знал,
 * что raw-SQL индекс должен существовать, и удалил его как "лишний". Теперь
 * #[ORM\UniqueConstraint] добавлен в саму Entity (см. PLCategory.php) —
 * маппинг и схема синхронизированы, повторного дропа при будущих diff не будет.
 *
 * Перед созданием индекса безопасно разрешает уже существующие дубли
 * (company_id, code): на одной строке дубля code сохраняется (детерминированно,
 * по наименьшей id — у PLCategory нет created_at, восстановить хронологию
 * нечем), у остальных обнуляется. Категории не удаляются и не теряются —
 * только поле code. Согласовано с Владельцем как стандартное поведение
 * миграции независимо от того, есть ли дубли на момент запуска.
 */
final class Version20260804120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore uniq_plcat_company_code on pl_categories after resolving any existing duplicates';
    }

    public function up(Schema $schema): void
    {
        // Только чтение — безопасно выполнять напрямую даже в --dry-run.
        $duplicateRows = (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY company_id, code ORDER BY id) AS rn
                    FROM pl_categories
                    WHERE code IS NOT NULL
                ) ranked
                WHERE rn > 1
                SQL,
        );

        if ($duplicateRows > 0) {
            $this->write(sprintf(
                'uniq_plcat_company_code: found %d duplicate pl_categories row(s) by (company_id, code); will null code on all but the lowest id per group.',
                $duplicateRows,
            ));

            // addSql(), не connection->executeStatement(): --dry-run только
            // печатает поставленные в очередь addSql()-запросы и не исполняет
            // их. Прямой executeStatement() эту очередь обходит и обнулил бы
            // code по-настоящему даже в --dry-run.
            $this->addSql(
                <<<'SQL'
                    UPDATE pl_categories SET code = NULL
                    WHERE id IN (
                        SELECT id FROM (
                            SELECT id, ROW_NUMBER() OVER (PARTITION BY company_id, code ORDER BY id) AS rn
                            FROM pl_categories
                            WHERE code IS NOT NULL
                        ) ranked
                        WHERE rn > 1
                    )
                    SQL,
            );
        }

        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_plcat_company_code ON pl_categories (company_id, code)');
    }

    public function down(Schema $schema): void
    {
        // Обнулённые в up() дубликаты code не восстанавливаются — down()
        // откатывает только схему (индекс), не данные.
        $this->addSql('DROP INDEX IF EXISTS uniq_plcat_company_code');
    }
}
