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
}
