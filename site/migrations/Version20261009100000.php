<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Пересчёт pl_categories.level по фактической глубине в дереве.
 *
 * До исправления PLCategory::setParent() при переносе статьи потомки сохраняли
 * старый уровень. Отчёт ОПиУ считает строку группой, если следующая строка
 * глубже, и у ИП Лазарева показал листовую статью «Налоги на ФОТ» как группу.
 *
 * Замер на проде 2026-10-09: 325 статей, циклов нет, глубина до 3, расходятся 11:
 * ИП Лазарева — 8 детей «Общепроизводственные» (3 → 2), Ваш ФинДир — 3 ребёнка
 * «Прямые затраты услуг CFO» (2 → 3). up() пересчитывает все строки, где уровень
 * расходится. down() возвращает эти 11 значений, если строку с тех пор не меняли.
 */
final class Version20261009100000 extends AbstractMigration
{
    /** Уровни до миграции: id => [было, стало]. */
    private const PROD_BEFORE = [
        '29598e11-44ff-4e59-8e32-1c101ad0ef32' => [2, 3],
        'd15de783-7664-457e-bbf3-a3c7a888ee94' => [2, 3],
        'fe5b8392-2c9f-4d40-894e-aaf5a3f97aae' => [2, 3],
        '019fd25e-7310-708f-98c3-defcddae346c' => [3, 2],
        '019fd25e-7311-72f6-b3d7-b5108ac8c98b' => [3, 2],
        '019fd25e-7311-72f6-b3d7-b5108bac25a2' => [3, 2],
        '019fd25e-7312-7292-9429-0a96a1ff7da5' => [3, 2],
        '023ccd96-ce54-40df-8990-2f9076daa300' => [3, 2],
        '9853d3c4-44b4-4ded-a5be-d8c47a99bd46' => [3, 2],
        'b6684460-a59a-49d6-abaf-f1e8ead3a6a3' => [3, 2],
        'f900767c-2af9-4f22-b103-2a4f922c6096' => [3, 2],
    ];

    public function getDescription(): string
    {
        return 'Recalculate pl_categories.level from the actual tree depth';
    }

    public function up(Schema $schema): void
    {
        // Предел рекурсии страхует от цикла в parent_id: на проде циклов нет,
        // но без предела цикл повесил бы миграцию.
        $this->addSql(<<<'SQL'
            WITH RECURSIVE depth (id, level) AS (
                SELECT id, 1 FROM pl_categories WHERE parent_id IS NULL
                UNION ALL
                SELECT c.id, d.level + 1
                FROM pl_categories c
                JOIN depth d ON c.parent_id = d.id
                WHERE d.level < 32
            )
            UPDATE pl_categories p
            SET level = depth.level
            FROM depth
            WHERE depth.id = p.id
              AND p.level <> depth.level
            SQL);
    }

    public function down(Schema $schema): void
    {
        foreach (self::PROD_BEFORE as $id => [$before, $after]) {
            $this->addSql(
                'UPDATE pl_categories SET level = :before WHERE id = :id AND level = :after',
                ['id' => $id, 'before' => $before, 'after' => $after],
            );
        }
    }
}
