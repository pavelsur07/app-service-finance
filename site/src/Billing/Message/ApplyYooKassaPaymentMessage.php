<?php

declare(strict_types=1);

namespace App\Billing\Message;

/**
 * Проверить платёж ЮKassa и применить его к попытке оплаты (уведомление ЮKassa или cron-сверка).
 */
final readonly class ApplyYooKassaPaymentMessage
{
    public function __construct(
        public string $providerPaymentId,
    ) {
    }
}
