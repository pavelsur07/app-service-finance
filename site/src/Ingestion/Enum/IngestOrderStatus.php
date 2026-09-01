<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

/**
 * Сквозной статус заказа, единый для всех маркетплейсов.
 *
 * Существует затем, чтобы выкуп считался одинаково для Ozon и WB. Сырая строка
 * маркетплейса хранится рядом и не заменяется этим значением: она —
 * доказательство при разборе, а enum — то, о чём можно спрашивать.
 */
enum IngestOrderStatus: string
{
    case ORDERED = 'ordered';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    /**
     * Значение не распознано словарём. Не ошибка и не NULL: заказ существует,
     * просто маркетплейс прислал незнакомый токен. Попадает в видимую очередь
     * на разбор.
     */
    case UNKNOWN = 'unknown';

    /**
     * Терминальность определяется ЗДЕСЬ и только здесь: её спрашивают из
     * выборки на перепроверку, из монитора зависших и из апсерта. Три копии
     * предиката рано или поздно разошлись бы.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::DELIVERED, self::CANCELLED, self::RETURNED => true,
            self::ORDERED, self::SHIPPED, self::UNKNOWN => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ORDERED => 'Заказан',
            self::SHIPPED => 'В доставке',
            self::DELIVERED => 'Доставлен',
            self::CANCELLED => 'Отменён',
            self::RETURNED => 'Возвращён',
            self::UNKNOWN => 'Неизвестный статус',
        };
    }
}
