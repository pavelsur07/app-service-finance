<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

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
 * Поэтому список берётся из состояния, а не из календаря: пересобираем каждый
 * период, который сейчас ЗАКРЫТ предварительно. Окончательно закрытые сюда не
 * попадают — их замена не трогает вовсе; переоткрытые тоже, иначе пересбор
 * закрыл бы обратно период, который открыли руками ради правок.
 */
final class PreliminaryClosedPeriodsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{company_id: string, marketplace: string, year: int, month: int}>
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
            "SELECT company_id, marketplace, year, month
             FROM marketplace_month_closes
             WHERE (stage_sales_returns_status = 'closed'
                    AND settings->'last_close_was_preliminary'->>'sales_returns' = 'true')
                OR (stage_costs_status = 'closed'
                    AND settings->'last_close_was_preliminary'->>'costs' = 'true')
             ORDER BY year, month, company_id, marketplace",
        );

        return array_map(
            static fn (array $row): array => [
                'company_id' => (string) $row['company_id'],
                'marketplace' => (string) $row['marketplace'],
                'year' => (int) $row['year'],
                'month' => (int) $row['month'],
            ],
            $rows,
        );
    }
}
