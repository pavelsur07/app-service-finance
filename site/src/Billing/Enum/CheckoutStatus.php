<?php

declare(strict_types=1);

namespace App\Billing\Enum;

/**
 * Состояние попытки онлайн-оплаты.
 */
enum CheckoutStatus: string
{
    /** Создана, клиент оплачивает (или ссылка ещё не получена). */
    case PENDING = 'pending';
    /** Платёж прошёл и зачислен (`BillingPayment` с тем же ID). */
    case SUCCEEDED = 'succeeded';
    /** Платёж отменён или истёк в ЮKassa. */
    case CANCELED = 'canceled';
    /** Деньги списаны, но зачислить нельзя (правила тарифа) — разбирает администратор. */
    case FAILED_TO_APPLY = 'failed_to_apply';

    public function isFinal(): bool
    {
        return self::PENDING !== $this;
    }
}
