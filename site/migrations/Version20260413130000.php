<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Бэкфилл operation_type для исторических Wildberries-строк в marketplace_costs.
 *
 * Порядок (выполняется в одной транзакции Doctrine Migrations):
 *   1. Все WB-строки без operation_type  →  operation_type = 'charge'.
 *   2. Пост-ассерт: ни одной WB-строки с operation_type IS NULL.
 *   3. Инвариант: ни одной WB-строки с amount < 0
 *      (все 11 калькуляторов WB уже используют abs(), возвраты фильтруются
 *      на уровне процессора — см. WbCostsRawProcessor.php:72-74).
 *      Если условие нарушено — RAISE EXCEPTION и полный rollback транзакции.
 *
 * ВАЖНО:
 *  - Ozon- и прочие не-WB строки НЕ затрагиваются.
 *  - Значение маркетплейса в БД — 'wildberries' (MarketplaceType::WILDBERRIES->value),
 *    а не 'wb'.
 *  - Миграция необратима: восстановить исходный NULL по operation_type невозможно
 *    (и незачем — семантически это был charge).
 */
final class Version20260413130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill operation_type = CHARGE for Wildberries marketplace_costs; assert invariants (no NULL op_type, no negative amounts)';
    }

    public function up(Schema $schema): void
    {
        // Гарантируем доступность gen_random_uuid() на старых PostgreSQL < 13,
        // где функция требует расширения pgcrypto. На PG 13+ оно уже встроено,
        // IF NOT EXISTS делает оператор идемпотентным. Данный backfill
        // напрямую pgcrypto не использует, но расширение добавляется ради
        // согласованности с остальными data-миграциями модуля.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        // Step 1 — default CHARGE для всех WB-строк без operation_type.
        $this->addSql(<<<'SQL'
            UPDATE marketplace_costs
            SET operation_type = 'charge'
            WHERE marketplace = 'wildberries'
              AND operation_type IS NULL
        SQL);

        // Step 2 & 3 — пост-ассерты в одном DO-блоке.
        // При несоответствии RAISE EXCEPTION откатывает всю транзакцию миграции.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                remaining_null_op  integer;
                remaining_negative integer;
            BEGIN
                -- Step 2: не должно остаться WB-строк с NULL operation_type.
                SELECT COUNT(*) INTO remaining_null_op
                FROM marketplace_costs
                WHERE marketplace = 'wildberries'
                  AND operation_type IS NULL;

                IF remaining_null_op > 0 THEN
                    RAISE EXCEPTION
                        'WB operation_type backfill failed: % WB row(s) still have NULL operation_type',
                        remaining_null_op;
                END IF;

                -- Step 3: инвариант — у WB не должно быть отрицательных сумм.
                -- Все калькуляторы применяют abs(), возвраты фильтруются в процессоре.
                SELECT COUNT(*) INTO remaining_negative
                FROM marketplace_costs
                WHERE marketplace = 'wildberries'
                  AND amount < 0;

                IF remaining_negative > 0 THEN
                    RAISE EXCEPTION
                        'WB operation_type backfill failed: invariant violated — % WB row(s) have negative amount',
                        remaining_negative;
                END IF;
            END $$;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Data migration is irreversible: original NULL state of operation_type is not '
            . 'recoverable (and semantically meaningless — all WB rows are CHARGE). '
            . 'Restore marketplace_costs from backup if needed.',
        );
    }
}
