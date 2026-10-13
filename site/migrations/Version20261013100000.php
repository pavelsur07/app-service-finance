<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Дропофф АПВЗ Ozon (ozon_dropoff_apvz) — в «Логистику до клиента» у всех компаний, как в базовом
 * маппинге: Drop-off у партнёров — та же отправка покупателю, что Drop-off в ПВЗ/СЦ, а не приёмка
 * поставки. Решение Владельца 13.10.2026.
 *
 * Автонастройка существующие маппинги не перезаписывает, поэтому у компаний правка делается данными.
 * Компании и их текущие статьи перечислены явно (прод на 13.10.2026): затрагивается только строка,
 * которая сейчас указывает именно на эту статью, — ручной выбор другой не перетирается, и down()
 * возвращает каждой компании её прежнюю статью. Уже проведённые в ОПиУ документы не пересчитываются —
 * правка действует на затраты, ещё не попавшие в документ.
 * Без нужной категории у компании UPDATE ничего не меняет (NULL не подставляется).
 */
final class Version20261013100000 extends AbstractMigration
{
    private const TARGET_CODE = 'COGS_DELIVERY';

    /** @var array<string, string> company_id => прежний pl_code */
    private const PREVIOUS_CODES = [
        'b57d7682-505f-4b74-86f8-953d32d59874' => 'OPEX_WH_RECEIVING', // ИП Сухоносов А. В.
        '3f4d87cc-a967-43a7-9b5e-8a9113c0a910' => 'OPEX_WH_RECEIVING', // ИП Лазарева
        '19621cff-b028-45d9-9193-11f47ad9a8b2' => 'MP_STORAGE',        // Вумджой ООО
    ];

    public function getDescription(): string
    {
        return 'Remap Ozon ozon_dropoff_apvz cost category to COGS_DELIVERY for all companies';
    }

    public function up(Schema $schema): void
    {
        foreach (self::PREVIOUS_CODES as $companyId => $previousCode) {
            $this->remap($companyId, $previousCode, self::TARGET_CODE);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::PREVIOUS_CODES as $companyId => $previousCode) {
            $this->remap($companyId, self::TARGET_CODE, $previousCode);
        }
    }

    private function remap(string $companyId, string $fromCode, string $toCode): void
    {
        $this->addSql(<<<'SQL'
            UPDATE marketplace_cost_pl_mappings m
            SET pl_category_id = target.id,
                updated_at = (now() AT TIME ZONE 'Europe/Moscow')
            FROM marketplace_cost_categories cc,
                 pl_categories source,
                 pl_categories target
            WHERE cc.id = m.cost_category_id
              AND cc.marketplace = 'ozon'
              AND cc.code = 'ozon_dropoff_apvz'
              AND m.company_id = :company_id
              AND source.id = m.pl_category_id
              AND source.company_id = m.company_id
              AND source.code = :from_code
              AND target.company_id = m.company_id
              AND target.code = :to_code
        SQL, [
            'company_id' => $companyId,
            'from_code' => $fromCode,
            'to_code' => $toCode,
        ]);
    }
}
