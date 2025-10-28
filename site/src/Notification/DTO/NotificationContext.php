<?php

namespace App\Notification\DTO;

final class NotificationContext
{
    public function __construct(
        public readonly ?string $companyId = null,     // multi-tenant
        public readonly ?string $locale = 'ru',        // локаль шаблонов
        public readonly ?string $idempotencyKey = null, // антидубль опционально
    ) {
    }
}
