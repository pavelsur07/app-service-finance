<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Бэкфилл operation_type и нормализация знака amount для исторических Ozon-строк
 * в marketplace_costs.
 *
 * Порядок (выполняется в одной транзакции Doctrine Migrations):
 *   0. Пре-создание категории `ozon_decompensation` для каждой компании, у которой
 *      есть `ozon_compensation` и хотя бы одна отрицательная строка в ней.
 *      Копируются pl_category_id / description / is_system из оригинала,
 *      name = 'Декомпенсация Ozon'.
 *   1. Все Ozon-строки без operation_type  →  operation_type = 'charge'.
 *   2. Отрицательные Ozon-строки, кроме ozon_compensation / ozon_decompensation,
 *      →  operation_type = 'storno' + amount = ABS(amount).
 *   3. Отрицательные ozon_compensation  →  смена категории на company-scoped
 *      ozon_decompensation + amount = ABS(amount) + operation_type = 'charge'.
 *   4. Пост-ассерт: в Ozon-строках не осталось amount < 0. Иначе — RAISE EXCEPTION
 *      и полный rollback транзакции.
 *
 * ВАЖНО:
 *  - WB- и прочие не-Ozon строки НЕ затрагиваются.
 *  - Закрытые строки (document_id IS NOT NULL) ОБНОВЛЯЮТСЯ — operation_type это
 *    метаданные, а нормализация знака не меняет факт суммы (|amount| остаётся тем же
 *    по модулю; общая сумма после Step 2–3 совпадает с ABS до миграции).
 *  - Миграция необратима (стирает исходный знак amount и пересаживает category_id).
 *
 * Порядок деплоя:
 *  Эту миграцию нельзя раскатывать ДО того, как downstream-queries
 *  (UnprocessedCostsQuery, ListingCostAggregateQuery, PreflightCostsQuery,
 *  CostReconciliationQuery, CostsVerifyQuery) будут мигрированы на
 *  `operation_type`-aware условия. См. discussion в PR #1503.
 */
final class Version20260413120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill operation_type and normalize amount sign for Ozon marketplace_costs; route negative compensations to ozon_decompensation';
    }

    public function up(Schema $schema): void
    {
        // Гарантируем доступность gen_random_uuid() на старых PostgreSQL < 13,
        // где функция требует расширения pgcrypto. На PG 13+ оно уже встроено,
        // IF NOT EXISTS делает оператор идемпотентным.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        // Step 0 — пре-создать категорию ozon_decompensation для каждой компании,
        // у которой есть ozon_compensation И отрицательные строки в ней.
        // Копируем pl_category_id / description / is_system из ozon_compensation,
        // чтобы сохранить связь с ОПиУ и системность.
        $this->addSql(<<<'SQL'
            INSERT INTO marketplace_cost_categories (
                id, company_id, marketplace, name, code, pl_category_id,
                description, is_system, deleted_at, is_active, created_at, updated_at
            )
            SELECT
                gen_random_uuid(),
                src.company_id,
                'ozon',
                'Декомпенсация Ozon',
                'ozon_decompensation',
                src.pl_category_id,
                src.description,
                src.is_system,
                NULL,
                TRUE,
                NOW(),
                NOW()
            FROM marketplace_cost_categories src
            WHERE src.marketplace = 'ozon'
              AND src.code = 'ozon_compensation'
              -- src.deleted_at НЕ фильтруем: soft-deleted ozon_compensation всё равно
              -- может иметь исторические marketplace_costs с amount<0, которые Step 3
              -- должен переложить в ozon_decompensation. Иначе lost_category assert.
              AND NOT EXISTS (
                  SELECT 1 FROM marketplace_cost_categories tgt
                  WHERE tgt.company_id = src.company_id
                    AND tgt.marketplace = 'ozon'
                    AND tgt.code = 'ozon_decompensation'
              )
              AND EXISTS (
                  SELECT 1 FROM marketplace_costs c
                  WHERE c.category_id = src.id
                    AND c.amount < 0
              )
        SQL);

        // Step 1 — default CHARGE для всех Ozon-строк без operation_type.
        $this->addSql(<<<'SQL'
            UPDATE marketplace_costs
            SET operation_type = 'charge'
            WHERE marketplace = 'ozon'
              AND operation_type IS NULL
        SQL);

        // Step 2 — помечаем настоящие сторно: отрицательные amount, исключая
        // (де)компенсации (они CHARGE даже при отрицательном знаке).
        $this->addSql(<<<'SQL'
            UPDATE marketplace_costs AS c
            SET operation_type = 'storno',
                amount = ABS(c.amount)
            FROM marketplace_cost_categories AS mcc
            WHERE c.category_id = mcc.id
              AND c.marketplace = 'ozon'
              AND c.amount < 0
              AND mcc.code <> 'ozon_compensation'
              AND mcc.code <> 'ozon_decompensation'
        SQL);

        // Step 3 — перенос отрицательных ozon_compensation в ozon_decompensation:
        //   • смена category_id на target категорию ТОЙ ЖЕ компании (company-scoped);
        //   • abs(amount);
        //   • operation_type = 'charge' (компенсация/декомпенсация — это CHARGE).
        // INNER JOIN вместо коррелированного подзапроса — быстрее на больших объёмах.
        // tgt.deleted_at НЕ фильтруем (согласуется со Step 0) — target создаётся Step 0
        // без deleted_at, и все существующие tgt-кандидаты равноценны.
        $this->addSql(<<<'SQL'
            UPDATE marketplace_costs AS c
            SET category_id = tgt.id,
                amount = ABS(c.amount),
                operation_type = 'charge'
            FROM marketplace_cost_categories AS mcc
            INNER JOIN marketplace_cost_categories AS tgt
                ON tgt.company_id = mcc.company_id
               AND tgt.marketplace = mcc.marketplace
               AND tgt.code = 'ozon_decompensation'
            WHERE c.category_id = mcc.id
              AND c.marketplace = 'ozon'
              AND c.amount < 0
              AND mcc.code = 'ozon_compensation'
        SQL);

        // Step 4 — пост-ассерт: ни одной Ozon-строки с amount < 0 не должно остаться.
        // А также ни одного Ozon-row без operation_type. При несоответствии —
        // RAISE EXCEPTION откатывает всю транзакцию.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                remaining_negative integer;
                remaining_null_op  integer;
                lost_category      integer;
            BEGIN
                SELECT COUNT(*) INTO remaining_negative
                FROM marketplace_costs
                WHERE marketplace = 'ozon' AND amount < 0;

                IF remaining_negative > 0 THEN
                    RAISE EXCEPTION
                        'Ozon operation_type backfill failed: % row(s) with negative amount remain',
                        remaining_negative;
                END IF;

                SELECT COUNT(*) INTO remaining_null_op
                FROM marketplace_costs
                WHERE marketplace = 'ozon' AND operation_type IS NULL;

                IF remaining_null_op > 0 THEN
                    RAISE EXCEPTION
                        'Ozon operation_type backfill failed: % Ozon row(s) have NULL operation_type',
                        remaining_null_op;
                END IF;

                -- Таргетированная проверка: должны быть 0 рядов, которые Step 3
                -- должен был переложить (negative ozon_compensation), но не переложил.
                -- Не проверяем general `category_id IS NULL`, т.к. это легитимное
                -- состояние с Version20260406000001 (ON DELETE SET NULL).
                SELECT COUNT(*) INTO lost_category
                FROM marketplace_costs c
                JOIN marketplace_cost_categories mcc ON mcc.id = c.category_id
                WHERE c.marketplace = 'ozon'
                  AND mcc.code = 'ozon_compensation'
                  AND c.amount < 0;

                IF lost_category > 0 THEN
                    RAISE EXCEPTION
                        'Ozon operation_type backfill failed: % negative ozon_compensation row(s) were not migrated to ozon_decompensation (missing target?)',
                        lost_category;
                END IF;
            END $$;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Data migration is irreversible: original sign of amount and original category_id '
            . 'of compensation→decompensation rows are not recoverable. Restore marketplace_costs from backup.',
        );
    }
}
