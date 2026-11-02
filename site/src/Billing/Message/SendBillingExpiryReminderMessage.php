<?php

declare(strict_types=1);

namespace App\Billing\Message;

/**
 * Письмо владельцу кабинета: оплаченный (или пробный) период заканчивается `paidUntil`
 * (Y-m-d), до конца `daysBefore` дней (0 — сегодня последний день).
 */
final readonly class SendBillingExpiryReminderMessage
{
    public function __construct(
        public string $companyId,
        public string $paidUntil,
        public int $daysBefore,
        public bool $trial,
    ) {
    }
}
