<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Переносит затраты by-day, ушедшие в «неразобранные» до разметки услуг Ozon,
 * в категории каталога, куда их теперь разбирает OzonAccrualServiceCategoryResolver.
 *
 * PremiumSubscription (type 52) → ozon_premium_promotion: те же 24 990 и
 * 9 990 руб. в том же ритме с января приходили туда легаси-путём.
 * TemporaryPlacement (type 78) → ozon_temporary_storage: решение Владельца.
 *
 * Не повторный разбор сырья: он удаляет и заново пишет затраты всего документа.
 * Здесь меняется только категория строки (и её описание, если оно было именем
 * неразобранной категории). Суммы, знак, external_id и сырьё не трогаются.
 * Строки в документе ОПиУ и в заблокированном периоде компании не переносятся.
 * Опустевшие неразобранные категории мягко удаляются; если услуга снова придёт
 * неразобранной, резолвер категорий вернёт удалённую, а не создаст дубль.
 */
final class Version20260926120000 extends AbstractMigration
{
    private const MOVES = <<<'SQL'
        (VALUES
            ('ozon_unknown_52', 'ozon_premium_promotion', '%-type-52'),
            ('ozon_unknown_78', 'ozon_temporary_storage', '%-type-78')
        ) AS m(unknown_code, target_code, external_id_pattern)
        SQL;

    public function getDescription(): string
    {
        return 'Move Ozon by-day costs of PremiumSubscription and TemporaryPlacement out of unclassified categories.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(<<<'SQL'
            UPDATE marketplace_costs c
            SET category_id = t.id,
                description = CASE WHEN c.description = u.name THEN t.name ELSE c.description END,
                updated_at = NOW()
            FROM marketplace_cost_categories u
            JOIN %s ON m.unknown_code = u.code
            JOIN marketplace_cost_categories t
                ON t.company_id = u.company_id
               AND t.marketplace = u.marketplace
               AND t.code = m.target_code
               AND t.deleted_at IS NULL
            JOIN companies co ON co.id = u.company_id
            WHERE u.marketplace = 'ozon'
              AND c.category_id = u.id
              AND c.company_id = u.company_id
              AND c.document_id IS NULL
              AND (co.finance_lock_before IS NULL OR c.cost_date::date > co.finance_lock_before)
            SQL, self::MOVES));

        $this->addSql(<<<'SQL'
            UPDATE marketplace_cost_categories u
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE u.marketplace = 'ozon'
              AND u.code IN ('ozon_unknown_52', 'ozon_unknown_78')
              AND u.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM marketplace_costs c WHERE c.category_id = u.id)
            SQL);
    }

    /**
     * Возвращает в неразобранные строки этих услуг, ещё не попавшие в документ
     * ОПиУ, — в том числе пришедшие by-day после деплоя: другого признака
     * перенесённой строки, кроме type в external_id, нет.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE marketplace_cost_categories u
            SET deleted_at = NULL, updated_at = NOW()
            WHERE u.marketplace = 'ozon'
              AND u.code IN ('ozon_unknown_52', 'ozon_unknown_78')
              AND u.deleted_at IS NOT NULL
            SQL);

        $this->addSql(sprintf(<<<'SQL'
            UPDATE marketplace_costs c
            SET category_id = u.id,
                description = CASE WHEN c.description = t.name THEN u.name ELSE c.description END,
                updated_at = NOW()
            FROM marketplace_cost_categories t
            JOIN %s ON m.target_code = t.code
            JOIN marketplace_cost_categories u
                ON u.company_id = t.company_id
               AND u.marketplace = t.marketplace
               AND u.code = m.unknown_code
            WHERE t.marketplace = 'ozon'
              AND c.category_id = t.id
              AND c.company_id = t.company_id
              AND c.document_id IS NULL
              AND c.external_id LIKE m.external_id_pattern
            SQL, self::MOVES));
    }
}
