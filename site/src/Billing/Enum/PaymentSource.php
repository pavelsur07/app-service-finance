<?php

declare(strict_types=1);

namespace App\Billing\Enum;

/**
 * Откуда пришла оплата. Вместе с `externalId` — естественный ключ повтора.
 */
enum PaymentSource: string
{
    /** Счёт юрлицу, зачисление отмечает администратор. */
    case MANUAL_INVOICE = 'manual_invoice';

    /** Онлайн-оплата через ЮKassa; `externalId` — ID платежа ЮKassa. */
    case YOOKASSA = 'yookassa';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL_INVOICE => 'Счёт',
            self::YOOKASSA => 'ЮKassa',
        };
    }
}
