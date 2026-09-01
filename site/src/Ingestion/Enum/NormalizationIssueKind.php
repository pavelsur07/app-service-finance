<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum NormalizationIssueKind: string
{
    case SUM_MISMATCH = 'sum_mismatch';
    case MAPPER_FAILURE = 'mapper_failure';
    case UNKNOWN_FIELD = 'unknown_field';
    case CURRENCY_MISMATCH = 'currency_mismatch';

    /**
     * Маркетплейс прислал статус заказа, которого нет в словаре. Заказ
     * сохраняется со статусом UNKNOWN — это видимая очередь на разбор, а не
     * потеря данных.
     */
    case UNKNOWN_ORDER_STATUS = 'unknown_order_status';

    /**
     * Заказ слишком долго висит в нетерминальном статусе. Опрашивать его
     * дальше бессмысленно, но и молча забывать нельзя.
     */
    case STUCK_ORDER = 'stuck_order';

    public function label(): string
    {
        return match ($this) {
            self::SUM_MISMATCH => 'Сумма не сошлась',
            self::MAPPER_FAILURE => 'Ошибка маппинга',
            self::UNKNOWN_FIELD => 'Неизвестное поле',
            self::CURRENCY_MISMATCH => 'Несовпадение валют',
            self::UNKNOWN_ORDER_STATUS => 'Неизвестный статус заказа',
            self::STUCK_ORDER => 'Заказ завис в нетерминальном статусе',
        };
    }
}
