<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\CloseStage;
use Doctrine\DBAL\Connection;

/**
 * Периоды, закрытые ПРЕДВАРИТЕЛЬНО хотя бы по одному этапу.
 *
 * Нужен ночному пересбору. Загрузка by-day снимает привязку к предварительному
 * ОПиУ и заменяет строки любого дня, который ей велели перезалить, — а окно
 * команды доходит до 365 дней. Пересбор, привязанный к календарному окну,
 * оставил бы документ такого месяца расходиться с источником навсегда: ровно тот
 * дефект, ради которого всё это и делается.
 *
 * Выбираются только ОТМЕЧЕННЫЕ периоды: отметку ставит замена строк, когда сняла
 * привязку к предварительному ОПиУ, и снимает успешный пересбор. Сканировать
 * «все предварительно закрытые» нельзя — успешный пересбор снова выставляет флаг
 * предварительности, период остался бы в выборке вечно, и нетронутые
 * исторические документы удалялись бы и пересоздавались каждую ночь. По отметке
 * же в выборку не попадают ни заблокированные периоды (замена их не трогает, а
 * значит и не пачкает), ни те, где ничего не менялось.
 *
 * Вместе с периодом возвращается точный список этапов: период попадает в выборку
 * из-за одного конкретного, и соседний трогать нельзя — он может быть открыт
 * человеком ради правок. Поэтому отметка проверяется В ПАРЕ со статусом и
 * флагом предварительности этапа.
 */
final class PreliminaryClosedPeriodsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{company_id: string, marketplace: string, year: int, month: int, stages: list<string>}>
     */
    public function execute(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            // Флаг предварительности проверяется В ПАРЕ со статусом этапа.
            // `reopenStage()` переводит этап в REOPENED, но флаг не сбрасывает:
            // без пары ночной пересбор закрыл бы обратно период, который
            // человек намеренно открыл для правок, — возможно, посреди работы.
            //
            // Сравнение идёт через `->>`, а не оператором `@>`: колонка
            // `settings` объявлена как `json`, а containment есть только у
            // `jsonb`, и запрос падал бы целиком, обрушая весь пересбор.
            "SELECT company_id, marketplace, year, month,
                    (stage_sales_returns_status = 'closed'
                     AND settings->'last_close_was_preliminary'->>'sales_returns' = 'true'
                     AND settings->'needs_preliminary_rebuild'->>'sales_returns' = 'true') AS sales_returns_preliminary,
                    (stage_costs_status = 'closed'
                     AND settings->'last_close_was_preliminary'->>'costs' = 'true'
                     AND settings->'needs_preliminary_rebuild'->>'costs' = 'true') AS costs_preliminary
             FROM marketplace_month_closes
             WHERE (stage_sales_returns_status = 'closed'
                    AND settings->'last_close_was_preliminary'->>'sales_returns' = 'true'
                    AND settings->'needs_preliminary_rebuild'->>'sales_returns' = 'true')
                OR (stage_costs_status = 'closed'
                    AND settings->'last_close_was_preliminary'->>'costs' = 'true'
                    AND settings->'needs_preliminary_rebuild'->>'costs' = 'true')
             ORDER BY year, month, company_id, marketplace",
        );

        return array_map(
            static function (array $row): array {
                $stages = [];
                if ((bool) $row['sales_returns_preliminary']) {
                    $stages[] = CloseStage::SALES_RETURNS->value;
                }
                if ((bool) $row['costs_preliminary']) {
                    $stages[] = CloseStage::COSTS->value;
                }

                return [
                    'company_id' => (string) $row['company_id'],
                    'marketplace' => (string) $row['marketplace'],
                    'year' => (int) $row['year'],
                    'month' => (int) $row['month'],
                    'stages' => $stages,
                ];
            },
            $rows,
        );
    }
}
